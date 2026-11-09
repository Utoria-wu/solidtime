[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$SourceDirectory,
    [Parameter(Mandatory)][ValidatePattern('^[0-9a-f]{40}$')][string]$Revision,
    [Parameter(Mandatory)][ValidatePattern('^[0-9A-Za-z.-]+$')][string]$Version,
    [Parameter(Mandatory)][string]$ImageTag,
    [switch]$UsePreparedFiles,
    [switch]$ExportTar,
    [switch]$PrepareOnly
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Invoke-Docker {
    param([string[]]$DockerArguments)
    & docker @DockerArguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed (exit $LASTEXITCODE). Fix the reported error, then run this script again."
    }
}

function Write-Utf8File {
    param([string]$Path, [string]$Content)
    [IO.File]::WriteAllText($Path, $Content.Replace("`r`n", "`n"), [Text.UTF8Encoding]::new($false))
}

$SourceDirectory = (Resolve-Path -LiteralPath $SourceDirectory).Path
$requiredFiles = @(
    'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    '.env.production', 'docker/prod/Dockerfile',
    'resources/js/app.ts'
)
foreach ($file in $requiredFiles) {
    if (-not (Test-Path -LiteralPath (Join-Path $SourceDirectory $file) -PathType Leaf)) {
        throw "Required source file is missing: $file"
    }
}

$productionDockerfile = Get-Content -LiteralPath (Join-Path $SourceDirectory 'docker/prod/Dockerfile') -Raw
$aptUpdateWithRetries = @'
RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && printf 'Acquire::Retries "3";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n' > /etc/apt/apt.conf.d/80-retries \
    && apt-get update;
'@
$productionDockerfile = $productionDockerfile.Replace('RUN apt-get update;', $aptUpdateWithRetries)
$firstStage = 'FROM composer:${COMPOSER_VERSION} AS vendor'
$applicationCopy = 'COPY --link . .'
if (($productionDockerfile.Split(@($firstStage), [StringSplitOptions]::None).Count -ne 2) -or
    ($productionDockerfile.Split(@($applicationCopy), [StringSplitOptions]::None).Count -ne 2)) {
    throw 'The production Dockerfile has changed. Review the build wrapper before using it.'
}

# Prepare dependencies and assets inside Linux layers. The final runtime comes
# from the project's production Dockerfile, including its PHP extensions.
$preparationStages = @'
FROM composer:${COMPOSER_VERSION} AS app-dependencies
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache
COPY composer.json composer.lock ./
COPY extensions ./extensions
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-interaction --prefer-dist --ignore-platform-reqs --no-scripts --no-autoloader

FROM node:20 AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY . .
COPY --from=app-dependencies /app/vendor ./vendor
RUN cp .env.production .env \
    && sed -i 's/^APP_VERSION=0.0.0$/APP_VERSION=0.22.1/;s/^APP_BUILD=0$/APP_BUILD=728acd0a/' .env \
    && rm .env.production
RUN if [ ! -f modules_statuses.json ]; then printf '{}\n' > modules_statuses.json; fi
RUN npm run build && rm -rf node_modules

'@
$generatedDockerfile = $productionDockerfile.Replace(
    $firstStage, $preparationStages + "`n" + $firstStage
).Replace($applicationCopy, 'COPY --link --from=frontend /app .')

# This Dockerfile-specific ignore file also keeps host-built dependencies,
# frontend output and local environment secrets out of the build context.
$ignoreContent = @'
.git
**/.git
.github
.oss-scanner
node_modules
**/node_modules
vendor
**/vendor
public/build
.env*
!.env.production
auth.json
**/auth.json
*.key
*.pem
*.tar
*.zip
*.log
storage/logs/*
storage/*.key
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*.php
docs
e2e
tests
coverage
playwright-report
test-results
'@
if ($UsePreparedFiles) {
    foreach ($file in @('vendor/autoload.php', 'public/build/manifest.json', '.env')) {
        if (-not (Test-Path -LiteralPath (Join-Path $SourceDirectory $file) -PathType Leaf)) {
            throw "Prepared build file is missing: $file. Run without -UsePreparedFiles to prepare everything automatically."
        }
    }
    $preparedEnvironment = Get-Content -LiteralPath (Join-Path $SourceDirectory '.env') -Raw
    $expectedEnvironment = (Get-Content -LiteralPath (Join-Path $SourceDirectory '.env.production') -Raw).
        Replace('APP_VERSION=0.0.0', 'APP_VERSION=0.22.1').Replace('APP_BUILD=0', 'APP_BUILD=728acd0a')
    if ($preparedEnvironment.Trim() -ne $expectedEnvironment.Trim()) {
        throw 'The prepared .env differs from the build template. Review it before packaging, or run without -UsePreparedFiles.'
    }
    $generatedDockerfile = $productionDockerfile
    $ignoreContent = (($ignoreContent -split '\r?\n') | Where-Object {
        $_ -notin @('vendor', '**/vendor', 'public/build')
    }) -join "`n"
    $ignoreContent += "`n!.env`n"
}
$generatedDockerfile = $generatedDockerfile.Replace('0.22.1', $Version).Replace('728acd0a', $Revision)
$generatedDockerfile = '# syntax=docker/dockerfile:1' + "`n" + $generatedDockerfile
$dockerfilePath = Join-Path $PSScriptRoot 'Solidtime.local.Dockerfile'
Write-Utf8File -Path $dockerfilePath -Content $generatedDockerfile
Write-Utf8File -Path ($dockerfilePath + '.dockerignore') -Content $ignoreContent

Write-Host "Source: $SourceDirectory"
Write-Host "Image:  $ImageTag"
Write-Host "Recipe: $dockerfilePath"
if ($PrepareOnly) {
    Write-Host 'Build files prepared. No Docker build was started.'
    return
}

Get-Command docker -ErrorAction Stop | Out-Null
$engine = & docker info --format '{{.OSType}}/{{.Architecture}}'
if ($LASTEXITCODE -ne 0) {
    throw 'Docker is unavailable. Start Docker Desktop, wait for the Linux engine, then retry.'
}
if ($engine -notlike 'linux/*') {
    throw "Select the Docker Linux engine before building. Current engine: $engine"
}

# A manual bind-mounted build can still be running from the earlier walkthrough.
$runningIds = @(& docker ps --quiet)
if ($LASTEXITCODE -ne 0) { throw 'Could not list running containers.' }
foreach ($containerId in $runningIds) {
    $mountsJson = & docker inspect --format '{{json .Mounts}}' $containerId
    if ($LASTEXITCODE -ne 0) { throw 'Could not inspect running containers.' }
    foreach ($mount in ($mountsJson | ConvertFrom-Json)) {
        if ($mount.Type -eq 'bind' -and $mount.Source -eq $SourceDirectory -and $mount.RW) {
            throw "Container $containerId is using the source directory. Wait for the manual build to finish, or press Ctrl+C in its terminal before running this script."
        }
    }
}

if ($UsePreparedFiles) {
    Write-Host 'Using prepared dependencies and frontend assets. Building the production runtime image.'
} else {
    Write-Host 'Building dependencies, frontend assets and the production image. Cached steps are reused.'
}
Invoke-Docker -DockerArguments @(
    'buildx', 'build', '--platform', 'linux/amd64', '--load', '--progress=plain',
    '--file', $dockerfilePath, '--tag', $ImageTag,
    '--label', 'org.opencontainers.image.source=https://github.com/Utoria-wu/solidtime',
    '--label', "org.opencontainers.image.revision=$Revision",
    $SourceDirectory
)

Write-Host 'Checking the image platform, runtime dependencies and application bootstrap.'
Invoke-Docker -DockerArguments @('image', 'inspect', $ImageTag, '--format', '{{.Os}}/{{.Architecture}} {{.Id}}')
Invoke-Docker -DockerArguments @('run', '--rm', '--network=none', '--entrypoint', 'composer', $ImageTag, 'check-platform-reqs', '--no-dev')
Invoke-Docker -DockerArguments @('run', '--rm', '--network=none', '--entrypoint', 'php', $ImageTag, 'artisan', '--version')
Invoke-Docker -DockerArguments @('run', '--rm', '--network=none', '--entrypoint', 'sh', $ImageTag, '-ec', 'test -s public/build/manifest.json; grep -E "^APP_(VERSION|BUILD)=" .env')

if ($ExportTar) {
    $archivePath = Join-Path $PSScriptRoot ("solidtime-" + $Version + "-" + $Revision.Substring(0, 8) + ".tar")
    if (Test-Path -LiteralPath $archivePath) {
        throw "The verified image is ready, but the export file already exists: $archivePath. Rename it before exporting again."
    }
    Invoke-Docker -DockerArguments @('image', 'save', '--output', $archivePath, $ImageTag)
    Write-Host "Exported: $archivePath"
}
Write-Host "Verified local image ready: $ImageTag"
