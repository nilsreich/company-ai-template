<?php

// Integration checks against the local fake installation and a disposable production project.
// Host-side PHP, no framework dependencies: only proc_open, streams and JSON.
declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);

$dev = ['docker', 'compose', '-f', 'compose.yaml', '-f', 'compose.dev.yaml'];
$smoke = ['docker', 'compose', '-p', 'company-ai-smoke', '-f', 'compose.yaml'];
$smokeEnv = array_merge(getenv(), ['WEB_PORT' => '8081', 'WEB_BIND_ADDRESS' => '127.0.0.1']);
$devUrl = rtrim((string) (getenv('E2E_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');

function run(array $args, ?array $env = null, ?string $input = null): string
{
    $descriptor = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $process = proc_open($args, $descriptor, $pipes, null, $env);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start: '.implode(' ', $args));
    }
    if ($input !== null) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException("Command failed ({$code}): ".implode(' ', $args)."\n{$stderr}\n{$stdout}");
    }

    return $stdout;
}

function isSmoke(array $args): bool
{
    global $smoke;

    return array_slice($args, 0, count($smoke)) === $smoke;
}

function cmd(array $args, ?string $input = null): string
{
    global $smokeEnv;

    return run($args, isSmoke($args) ? $smokeEnv : null, $input);
}

function fixture(string $action, int|string $identifier = 0): array
{
    global $dev;
    if ($action === 'state') {
        $query = sprintf(
            "SELECT json_build_object('id',d.id,'path',d.path,'sha256',d.sha256,'revision',d.revision,'execution_id',r.id,'status',r.status,'attempts',r.attempts) FROM tasks d JOIN executions r ON r.task_id=d.id WHERE d.id=%d ORDER BY r.id DESC LIMIT 1",
            (int) $identifier
        );

        return json_decode(cmd([...$dev, 'exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$1"', 'sh', $query]), true, 512, JSON_THROW_ON_ERROR);
    }

    return json_decode(cmd([...$dev, 'exec', '-T', '-u', 'www-data', 'app', 'php', 'tests/operations/fixture.php', $action, (string) $identifier]), true, 512, JSON_THROW_ON_ERROR);
}

function waitState(int $identifier, string $status, int $seconds): array
{
    $end = microtime(true) + $seconds;
    do {
        $state = fixture('state', $identifier);
        if (($state['status'] ?? null) === $status) {
            return $state;
        }
        sleep(2);
    } while (microtime(true) < $end);
    throw new AssertionError('Run did not reach '.$status.': '.json_encode($state));
}

function waitHttp(string $url): string
{
    for ($i = 0; $i < 45; $i++) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]));
        if (is_string($body)) {
            return $body;
        }
        sleep(2);
    }
    throw new AssertionError('HTTP not ready: '.$url);
}

function removeDirectory(string $directory): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

if (trim(cmd([...$smoke, 'ps', '-aq'])) !== '' || trim(run(['docker', 'volume', 'ls', '-q', '--filter', 'label=com.docker.compose.project=company-ai-smoke'])) !== '') {
    throw new RuntimeException('company-ai-smoke already exists; choose a clean test environment.');
}
echo "Create local fake job and kill worker during processing\n";
cmd([...$dev, 'up', '-d', '--no-build', 'worker']);
$record = fixture('create');
waitState($record['id'], 'running', 20);
cmd([...$dev, 'kill', '-s', 'SIGKILL', 'worker']);
cmd([...$dev, 'up', '-d', '--no-build', 'worker']);
echo "Waiting for the real 120-second queue reservation to expire\n";
$state = waitState($record['id'], 'succeeded', 155);
assert($state['attempts'] === 2 && $state['revision'] === 1);
echo "PASS: worker interruption recovered without duplicate business changes\n";
cmd([...$dev, 'restart', 'db', 'app', 'worker', 'web']);
waitHttp($devUrl.'/login');
assert(fixture('state', $record['id']) == $state);
echo "PASS: database and private upload persisted across restart\n";
$directory = sys_get_temp_dir().'/company-ai-backup-'.bin2hex(random_bytes(6));
mkdir($directory);
try {
    cmd([...$dev, 'stop', 'web', 'worker']);
    try {
        $dump = cmd([...$dev, 'exec', '-T', 'db', 'sh', '-c', 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc']);
        $archive = cmd([...$dev, 'exec', '-T', 'app', 'tar', '-C', 'storage/app/private', '-czf', '-', '.']);
        file_put_contents($directory.'/database.dump', $dump);
        file_put_contents($directory.'/uploads.tar.gz', $archive);
    } finally {
        cmd([...$dev, 'up', '-d', '--no-build', 'web', 'worker']);
    }
    try {
        cmd([...$smoke, 'up', '-d', 'db']);
        cmd([...$smoke, 'run', '--rm', 'app', 'php', 'artisan', 'migrate', '--force']);
        $count = cmd([...$smoke, 'exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT count(*) FROM users"']);
        assert(trim($count) === '0');
        cmd([...$smoke, 'up', '-d', '--no-build', 'app', 'worker', 'web']);
        assert(! str_contains(waitHttp('http://127.0.0.1:8081/login'), 'Lokale Entwicklungsanmeldung'));
        $routes = json_decode(cmd([...$smoke, 'exec', '-T', '-e', 'DEV_LOGIN_ENABLED=true', '-e', 'TELESCOPE_ENABLED=true', 'app', 'php', 'artisan', 'route:list', '--json']), true, 512, JSON_THROW_ON_ERROR);
        foreach ($routes as $route) {
            assert(! str_starts_with($route['uri'], 'auth/development') && ! str_starts_with($route['uri'], 'telescope'));
        }
        $tables = cmd([...$smoke, 'exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$1"', 'sh', "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"]);
        assert(! str_contains($tables, 'telescope_entries'));
        echo "PASS: fresh production startup, migrations, no seeds, no development login or Telescope\n";
        cmd([...$smoke, 'stop', 'web', 'worker']);
        cmd([...$smoke, 'exec', '-T', 'db', 'sh', '-c', 'pg_restore --clean --if-exists --no-owner -U "$POSTGRES_USER" -d "$POSTGRES_DB"'], file_get_contents($directory.'/database.dump'));
        cmd([...$smoke, 'exec', '-T', 'app', 'tar', '-C', 'storage/app/private', '-xzf', '-'], file_get_contents($directory.'/uploads.tar.gz'));
        $checksum = explode(' ', cmd([...$smoke, 'exec', '-T', 'app', 'sha256sum', 'storage/app/private/'.$record['path']]))[0];
        assert($checksum === $record['sha256']);
        $restored = cmd([...$smoke, 'exec', '-T', 'db', 'sh', '-c', 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "$1"', 'sh', "SELECT status || ':' || revision FROM tasks WHERE id={$record['id']}"]);
        assert(trim($restored) === 'in_review:1', $restored);
        cmd([...$smoke, 'up', '-d', '--no-build', 'web', 'worker']);
        waitHttp('http://127.0.0.1:8081/login');
        echo "PASS: backup restored into isolated production containers; file checksum and task state match\n";
        $backupDirectory = $directory.'/script-backup';
        run(['./bin/backup', $backupDirectory], array_merge(getenv(), ['COMPOSE_PROJECT_NAME' => 'company-ai-smoke', 'WEB_PORT' => '8081', 'WEB_BIND_ADDRESS' => '127.0.0.1']));
        $sums = file_get_contents($backupDirectory.'/SHA256SUMS');
        foreach (['database.dump', 'uploads.tar.gz'] as $file) {
            $expected = null;
            foreach (explode("\n", trim($sums)) as $line) {
                if (str_ends_with($line, $file)) {
                    $expected = explode(' ', $line)[0];
                }
            }
            assert($expected !== null && hash_equals($expected, hash_file('sha256', $backupDirectory.'/'.$file)));
        }
        assert(filesize($backupDirectory.'/database.dump') > 0);
        assert(filesize($backupDirectory.'/uploads.tar.gz') > 0);
        echo "PASS: documented backup script and archive checksums\n";
    } finally {
        cmd([...$smoke, 'down', '--volumes']);
    }
} finally {
    removeDirectory($directory);
}
