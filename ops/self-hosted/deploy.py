"""Deploy a prebuilt image to the existing Solidtime stack; restore image on failure.

No schema migrations. Runtime config and backups stay on the server, never in Git.
Run: python3 deploy.py --image TAG --expected-old-image sha256:... --revision SHA
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path('/home/ubuntu/solidtime')
SERVICES = ['app', 'queue', 'scheduler']

def run(args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)

def output(args):
    return run(args, stdout=subprocess.PIPE, text=True).stdout.strip()

def docker(*args):
    return ['sudo', '-n', 'docker', *args]

def compose(*args):
    return docker('compose', *args)

def inspect(service):
    return json.loads(output(docker('inspect', 'solidtime-' + service + '-1')))[0]

def counts():
    names = ['users', 'projects', 'tasks', 'time_entries']
    sql = 'select json_build_object(' + ','.join(
        "'%s',(select count(*) from %s)" % (name, name) for name in names) + ');'
    return json.loads(output(docker('exec', 'solidtime-database-1', 'psql', '-U', 'solidtime', '-d', 'solidtime', '-Atc', sql)))

def save(path, data):
    path.write_text(json.dumps(data, indent=2) + '\n')

def wait_healthy(image):
    for _ in range(48):
        states = [inspect(service) for service in SERVICES]
        if all(s['Image'] == image and s['State'].get('Health', {}).get('Status') == 'healthy' for s in states):
            return
        time.sleep(5)
    raise RuntimeError('Application services failed health checks')

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image', required=True)
    parser.add_argument('--expected-old-image', required=True)
    parser.add_argument('--revision', required=True)
    args = parser.parse_args()
    os.chdir(ROOT)
    os.umask(0o077)
    before = {name: inspect(name) for name in [*SERVICES, 'database', 'gotenberg']}
    if any(before[s]['Image'] != args.expected_old_image for s in SERVICES):
        raise RuntimeError('Current runtime changed; inspect again before deploying')
    config = json.loads(output(compose('config', '--format', 'json')))
    if any(str(config['services'][s].get('environment', {}).get('AUTO_DB_MIGRATE', 'false')).lower() not in ['false','0',''] for s in SERVICES):
        raise RuntimeError('Automatic database migrations must be reviewed')
    new = json.loads(output(docker('image', 'inspect', args.image)))[0]
    if new['Config'].get('Labels', {}).get('org.opencontainers.image.revision') != args.revision:
        raise RuntimeError('Image source revision mismatch')
    for executable, arguments in [('php', ['artisan', '--version']), ('composer', ['check-platform-reqs', '--no-dev'])]:
        run(docker('run', '--rm', '--network=none', '--entrypoint', executable, args.image, *arguments))
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup = ROOT / 'backups' / ('task-status-' + stamp)
    backup.mkdir(parents=True, mode=0o700)
    for name in ['docker-compose.yml', 'docker-compose.override.yml', '.env', 'laravel.env']:
        if (ROOT / name).exists():
            shutil.copy2(ROOT / name, backup / name)
            (backup / name).chmod(0o600)
    with (backup / 'database.dump').open('wb') as dest:
        run(docker('exec', 'solidtime-database-1', 'pg_dump', '-U', 'solidtime', '-d', 'solidtime', '-Fc'), stdout=dest)
    with (backup / 'database.dump').open('rb') as source:
        run(docker('exec', '-i', 'solidtime-database-1', 'pg_restore', '--list'), stdin=source, stdout=subprocess.DEVNULL)
    mounts = {m['Destination']: m['Source'] for m in before['app']['Mounts']}
    for index, (destination, source) in enumerate(mounts.items()):
        if destination.startswith('/var/www/html/storage'):
            with (backup / ('storage-%d.tar.gz' % index)).open('wb') as dest:
                run(['sudo', '-n', 'tar', '-czf', '-', '-C', source, '.'], stdout=dest)
    before_counts = counts()
    manifest = {'old_image': args.expected_old_image, 'new_image': new['Id'], 'image_tag': args.image,
                'revision': args.revision, 'counts': before_counts, 'mounts': mounts,
                'backup': str(backup), 'database_container': before['database']['Id'],
                'gotenberg_container': before['gotenberg']['Id']}
    save(backup / 'manifest.json', manifest)
    save(backup / 'sha256.json', {p.name: hashlib.file_digest(p.open('rb'), 'sha256').hexdigest() for p in backup.iterdir() if p.is_file()})
    override = ROOT / 'docker-compose.override.yml'
    override_old = override.read_bytes() if override.exists() else None
    # Keep all current override settings. This deployment's existing override is JSON/YAML compatible.
    if override_old:
        try:
            override_data = json.loads(override_old)
        except json.JSONDecodeError:
            raise RuntimeError('Non-JSON override found: preserve its settings explicitly before deployment')
    else:
        override_data = {'services': {}}
    for service in SERVICES:
        override_data.setdefault('services', {}).setdefault(service, {}).update({'image': args.image, 'pull_policy': 'never'})
    try:
        save(override, override_data)
        resolved = json.loads(output(compose('config', '--format', 'json')))
        if any(resolved['services'][s]['image'] != args.image for s in SERVICES):
            raise RuntimeError('Compose image selection failed')
        run(compose('up', '-d', '--no-deps', '--pull', 'never', *SERVICES))
        wait_healthy(new['Id'])
        for path in ['/health-check/up', '/login']:
            run(['curl', '-fsS', '--max-time', '20', 'http://127.0.0.1:8000' + path], stdout=subprocess.DEVNULL)
        for service in ['database', 'gotenberg']:
            if inspect(service)['Id'] != before[service]['Id']:
                raise RuntimeError('Unexpected container replacement: ' + service)
        for service in SERVICES:
            if inspect(service)['Mounts'] != before[service]['Mounts']:
                raise RuntimeError('Storage mounts changed: ' + service)
        after_counts = counts()
        if any(after_counts[k] < v for k,v in before_counts.items()):
            raise RuntimeError('Data row count decreased during release')
        result = {**manifest, 'after_counts': after_counts, 'health': 'healthy'}
        save(backup / 'deployment-result.json', result)
        print(json.dumps(result), flush=True)
    except Exception:
        if override_old is None:
            override.unlink(missing_ok=True)
        else:
            override.write_bytes(override_old)
        run(compose('up', '-d', '--no-deps', '--pull', 'never', *SERVICES))
        wait_healthy(args.expected_old_image)
        print('Restored previous application image. Database was not rolled back.', flush=True)
        raise

if __name__ == '__main__':
    main()
