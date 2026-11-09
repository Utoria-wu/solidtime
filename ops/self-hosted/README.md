# Solidtime 自用发布流程

## 分支与版本

- `main`：跟随官方；不直接编写定制功能。
- `feature/*`：一个功能一个分支。向官方贡献时从合适的官方基线创建；只为自用且依赖已有定制时可从 `master` 创建。
- `master`：汇总通过测试的功能，是自用发布来源。
- `selfhosted-v0.22.1-2` 这样的 Git tag：标记本次发布的固定源码。
- 镜像标签同时包含版本和提交号；已发布的标签不复用。发布记录同时保留镜像 ID 和备份位置。

合入基于较新 `main` 的功能分支也会引入其官方祖先提交。只想发布定制功能时，保持当前官方基线。

## 1. 推送功能并合并

本仓库使用 `origin` 指向官方，`fork` 指向 `Utoria-wu/solidtime`。先用 `git remote -v` 核实；不要把自用分支推到官方。

```sh
git push fork feature/time-entry-task-status
git fetch fork
git switch master
git pull --ff-only fork master
git merge --no-ff feature/time-entry-task-status
```

也可以在自己的 GitHub 仓库创建 PR：base=master，compare=feature/time-entry-task-status。直接命令行 merge 和自己的 PR 都可以，选一种即可。

## 2. 验证合并后的版本

```sh
npm ci
npm run type-check
npx vitest run resources/js/packages/ui/src/TimeEntry/TimeEntryEditModal.test.ts resources/js/Components/Common/Project/ProjectTaskProgress.test.ts
npm run build
git push fork master
```

本功能分两次调用原有 API：先保存时间记录，成功后保存有改动的任务状态。若第二步失败，时间记录已保存，窗口保留以便重试。不是跨 API 的数据库事务。

## 3. 固定源码并构建镜像（Windows PowerShell）

Docker Desktop 需要处于 Linux engine 模式。`Build-Solidtime.ps1` 基于仓库的生产 Dockerfile 生成完整构建配方，在 Linux 构建阶段安装 Composer/npm 依赖并打包前端；不会把本机 `.env`、认证文件或 node_modules 打进镜像。

以下目录和版本每次发布使用新值；不要覆盖上一版：

```powershell
$repo = (Get-Location).Path
$release = '0.22.1-custom.2'
$revision = git rev-parse HEAD
$tag = 'selfhosted-v0.22.1-2'
$image = "solidtime-local:$release-$($revision.Substring(0,8))"
$bundle = Join-Path (Split-Path $repo -Parent) $tag
New-Item -ItemType Directory -Path $bundle -ErrorAction Stop
git archive --format=zip --output="$bundle/source.zip" $revision
Expand-Archive "$bundle/source.zip" "$bundle/source"
Copy-Item "$repo/ops/self-hosted/Build-Solidtime.ps1" "$bundle/Build-Solidtime.ps1"
& "$bundle/Build-Solidtime.ps1" -SourceDirectory "$bundle/source" -Revision $revision -Version $release -ImageTag $image -ExportTar
# 脚本失败时先解决错误，不执行后续步骤。
Get-FileHash "$bundle/solidtime-$release-$($revision.Substring(0,8)).tar" -Algorithm SHA256
```

构建脚本包含：docker buildx build --platform linux/amd64 --load、PHP 平台检查、Laravel 启动检查、前端 manifest 检查及 docker image save。首次较慢，之后可复用缓存。

## 4. 上传并装载镜像

用 `scp` 把镜像 tar 和 `ops/self-hosted/deploy.py` 传到服务器的本次发布目录。SSH 密码只交互输入，不写入代码或文档。校验上传后的 `sha256sum` 与本地一致，再 `sudo docker load -i 镜像文件.tar`。

这里的 `load` 只导入镜像，尚未切换线上容器。先检查新镜像标签和 revision label，确认与本次 Git 提交一致。

## 5. 备份与发布（Ubuntu）

在 `/home/ubuntu/solidtime` 下记录旧镜像：

```sh
sudo docker inspect solidtime-app-1 --format '{{.Image}}'
```

使用本次真实参数运行：

```sh
python3 /本次发布目录/deploy.py --image 新镜像标签 --expected-old-image 旧镜像完整sha256 --revision 本次完整Git提交号
```

脚本会检查线上版本未变化、禁止自动数据库迁移，备份数据库、存储、Compose 和环境配置，然后更新现有 override 中 app/queue/scheduler 的镜像，运行：

```sh
sudo docker compose up -d --no-deps --pull never app queue scheduler
```

它会等待健康检查，验证登录页、健康接口、存储挂载、数据量，并确认数据库/Gotenberg 容器未被替换。切换会有短暂服务中断。验证失败时自动恢复旧 override 和旧应用镜像。

该脚本针对本次部署：单机 Compose、现有 JSON 格式 override、无数据库迁移。以后需要迁移或配置结构变化时先调整流程，不盲目复用。

## 6. 验收与回滚

```sh
cd /home/ubuntu/solidtime
sudo docker compose ps
curl -fsS http://127.0.0.1:8000/health-check/up
```

浏览器打开时间记录编辑框：Task status 显示当前状态，选择 Active/Done 后保存；取消不更改任务；Project 任务统计随任务状态刷新。不要拿不应变化的真实任务做测试。

发布成功并验证后创建/推送 Git tag：

```sh
git tag -a selfhosted-v0.22.1-2 发布提交号 -m "Self-hosted release: task status editing"
git push fork selfhosted-v0.22.1-2
```

本次无数据库迁移，回滚只需恢复对应备份中的 docker-compose.override.yml，再执行同样的 compose up 命令，等待健康。不要恢复数据库快照，否则会覆盖发布后的业务数据。若以后涉及迁移，需要独立的数据库回滚方案。

`restart` 只重启现有容器；更换镜像用 `compose up -d` 重新创建容器。不要使用 `down -v`。不要删除旧镜像和备份，直到你明确决定清理。

官方 PR 是独立流程，不影响自用发布。按官方 CONTRIBUTING.md 先讨论并获认可；正式 Discussion 和 PR 描述由贡献者本人撰写。
