"""Integration checks against the local fake installation and a disposable production project."""
import json
import os
import pathlib
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
os.chdir(ROOT)
DEV = ['docker', 'compose', '-f', 'compose.yaml', '-f', 'compose.dev.yaml']
SMOKE = ['docker', 'compose', '-p', 'company-ai-smoke', '-f', 'compose.yaml']
env = {**os.environ, 'WEB_PORT': '8081', 'WEB_BIND_ADDRESS': '127.0.0.1'}
dev_url = os.environ.get('E2E_BASE_URL', 'http://127.0.0.1:8080').rstrip('/')

def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, env=env if args[:len(SMOKE)] == SMOKE else os.environ, **kwargs)
    if result.returncode:
        raise RuntimeError(f'Command failed ({result.returncode}): {args}\n{result.stderr.decode()}\n{result.stdout.decode(errors="replace")}')
    return result.stdout

def fixture(action, identifier=0):
    if action == 'state':
        query = f"SELECT json_build_object('id',d.id,'path',d.path,'sha256',d.sha256,'revision',d.revision,'run_id',r.id,'status',r.status,'attempts',r.attempts) FROM documents d JOIN ai_runs r ON r.document_id=d.id WHERE d.id={int(identifier)} ORDER BY r.id DESC LIMIT 1"
        return json.loads(run(DEV + ['exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$1"', 'sh', query]))
    return json.loads(run(DEV + ['exec', '-T', '-u', 'www-data', 'app', 'php', 'tests/operations/fixture.php', action, str(identifier)]))

def wait_state(identifier, status, seconds):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        state = fixture('state', identifier)
        if state['status'] == status:
            return state
        time.sleep(2)
    raise AssertionError(f'Run did not reach {status}: {state}')

def wait_http(url):
    for _ in range(45):
        try:
            with urllib.request.urlopen(url, timeout=3) as response:
                return response.read().decode()
        except (OSError, urllib.error.HTTPError):
            time.sleep(2)
    raise AssertionError('HTTP not ready: ' + url)

if run(SMOKE + ['ps', '-aq']).strip() or run(['docker', 'volume', 'ls', '-q', '--filter', 'label=com.docker.compose.project=company-ai-smoke']).strip():
    raise SystemExit('company-ai-smoke already exists; choose a clean test environment.')
print('Create local fake job and kill worker during processing', flush=True)
run(DEV + ['up', '-d', '--no-build', 'worker'])
record = fixture('create')
wait_state(record['id'], 'running', 20)
run(DEV + ['kill', '-s', 'SIGKILL', 'worker'])
run(DEV + ['up', '-d', '--no-build', 'worker'])
print('Waiting for the real 120-second queue reservation to expire', flush=True)
state = wait_state(record['id'], 'succeeded', 155)
assert state['attempts'] == 2 and state['revision'] == 1, state
print('PASS: worker interruption recovered without duplicate business changes', flush=True)
run(DEV + ['restart', 'db', 'app', 'worker', 'web'])
wait_http(dev_url + '/login')
assert fixture('state', record['id']) == state
print('PASS: database and private upload persisted across restart', flush=True)
with tempfile.TemporaryDirectory(prefix='company-ai-backup-') as directory:
    directory = pathlib.Path(directory)
    run(DEV + ['stop', 'web', 'worker'])
    try:
        dump = run(DEV + ['exec', '-T', 'db', 'sh', '-c', 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc'])
        archive = run(DEV + ['exec', '-T', 'app', 'tar', '-C', 'storage/app/private', '-czf', '-', '.'])
        (directory / 'database.dump').write_bytes(dump)
        (directory / 'uploads.tar.gz').write_bytes(archive)
    finally:
        run(DEV + ['up', '-d', '--no-build', 'web', 'worker'])
    try:
        run(SMOKE + ['up', '-d', 'db'])
        run(SMOKE + ['run', '--rm', 'app', 'php', 'artisan', 'migrate', '--force'])
        count = run(SMOKE + ['exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT count(*) FROM users"'])
        assert count.strip() == b'0'
        run(SMOKE + ['up', '-d', '--no-build', 'app', 'worker', 'web'])
        assert 'Lokale Entwicklungsanmeldung' not in wait_http('http://127.0.0.1:8081/login')
        routes = run(SMOKE + ['exec', '-T', '-e', 'DEV_LOGIN_ENABLED=true', '-e', 'TELESCOPE_ENABLED=true', 'app', 'php', 'artisan', 'route:list', '--json'])
        assert not any(route['uri'].startswith(('auth/development', 'telescope')) for route in json.loads(routes))
        query = "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
        tables = run(SMOKE + ['exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$1"', 'sh', query])
        assert b'telescope_entries' not in tables
        print('PASS: fresh production startup, migrations, no seeds, no development login or Telescope', flush=True)
        run(SMOKE + ['stop', 'web', 'worker'])
        run(SMOKE + ['exec', '-T', 'db', 'sh', '-c', 'pg_restore --clean --if-exists --no-owner -U "$POSTGRES_USER" -d "$POSTGRES_DB"'], input=dump)
        run(SMOKE + ['exec', '-T', 'app', 'tar', '-C', 'storage/app/private', '-xzf', '-'], input=archive)
        checksum = run(SMOKE + ['exec', '-T', 'app', 'sha256sum', 'storage/app/private/' + record['path']]).decode().split()[0]
        assert checksum == record['sha256']
        query = f"SELECT status || ':' || revision FROM documents WHERE id={record['id']}"
        restored = run(SMOKE + ['exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$1"', 'sh', query])
        assert restored.strip() == b'in_review:1', restored
        run(SMOKE + ['up', '-d', '--no-build', 'web', 'worker'])
        wait_http('http://127.0.0.1:8081/login')
        print('PASS: backup restored into isolated production containers; file checksum and document state match', flush=True)
        backup_directory = directory / 'script-backup'
        backup = subprocess.run(['./bin/backup', str(backup_directory)], env={**env, 'COMPOSE_PROJECT_NAME': 'company-ai-smoke'}, capture_output=True)
        if backup.returncode:
            raise RuntimeError(backup.stderr.decode() + backup.stdout.decode())
        run(['sha256sum', '-c', str(backup_directory / 'SHA256SUMS')])
        assert (backup_directory / 'database.dump').stat().st_size > 0
        assert (backup_directory / 'uploads.tar.gz').stat().st_size > 0
        print('PASS: documented backup script and archive checksums', flush=True)
    finally:
        run(SMOKE + ['down', '--volumes'])
