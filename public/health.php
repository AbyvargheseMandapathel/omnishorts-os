<?php

declare(strict_types=1);

/**
 * public/health.php — boot-proof health probe (no Laravel required).
 *
 * When the app itself 500s (missing .env, empty APP_KEY, dead DB, unwritable
 * storage), Laravel routes like /health cannot run either. Apache serves this
 * file directly, so it reports the same four diagnostics — PHP version, DB
 * connectivity, migration state, storage writability — plus the tail of
 * storage/logs/laravel.log, so a broken deploy never stays a mystery.
 *
 * SECURITY
 *   - Read-only: the only writes are tiny probe files it deletes immediately.
 *   - Never echoes secrets: no APP_KEY, DB/FTP password, Google client secret.
 *   - The laravel.log tail can contain stack traces and internal paths — treat
 *     this page like the log file it exposes. Ship it while you debug, delete
 *     it (`git checkout public/health.php` to restore) once the app boots.
 *   - Intentionally open: no token, because it must work when .env is unreadable
 *     (exactly the case it exists for).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);

/**
 * Read .env into [KEY => value]. Values keep inline content, with surrounding
 * quotes stripped. Never returns secrets into the response — callers decide
 * what to expose.
 *
 * @return array<string, string>
 */
function health_env(string $root): array
{
    $vars = [];
    $file = $root.'/.env';

    if (! is_file($file)) {
        return $vars;
    }

    foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $vars[$key] = $value;
    }

    return $vars;
}

/**
 * Connect to the DB configured in .env (sqlite or mysql) and run a trivial
 * query. Returns the PDO handle (or null) plus the reportable info array.
 *
 * @return array{pdo: ?PDO, info: array<string, mixed>}
 */
function health_db(string $root, array $env): array
{
    $driver = $env['DB_CONNECTION'] ?? 'mysql';

    try {
        if ($driver === 'sqlite') {
            $path = $env['DB_DATABASE'] ?? 'database/database.sqlite';
            if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
                $path = $root.'/'.$path;
            }

            $pdo = new PDO('sqlite:'.$path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1');

            return [
                'pdo' => $pdo,
                'info' => ['ok' => true, 'connected' => true, 'driver' => 'sqlite', 'database' => $path],
            ];
        }

        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '3306';
        $db = $env['DB_DATABASE'] ?? 'laravel';
        $user = $env['DB_USERNAME'] ?? 'root';
        $pass = $env['DB_PASSWORD'] ?? '';

        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('SELECT 1');

        return [
            'pdo' => $pdo,
            'info' => ['ok' => true, 'connected' => true, 'driver' => 'mysql', 'database' => $db, 'host' => $host],
        ];
    } catch (Throwable $e) {
        return [
            'pdo' => null,
            'info' => ['ok' => false, 'connected' => false, 'driver' => $driver, 'error' => $e->getMessage()],
        ];
    }
}

/**
 * Compare migration files in database/migrations against the migrations table.
 *
 * @return array<string, mixed>
 */
function health_migrations(string $root, ?PDO $pdo): array
{
    if ($pdo === null) {
        return ['ok' => false, 'detail' => 'Skipped — the database is not reachable.'];
    }

    try {
        $ran = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (str_contains($message, 'no such table') || str_contains($message, "doesn't exist")) {
            return [
                'ok' => false,
                'table_exists' => false,
                'detail' => 'The migrations table is missing — run "php artisan migrate --force" (or open public/setup.php).',
            ];
        }

        return ['ok' => false, 'error' => $message];
    }

    $files = [];
    foreach (glob($root.'/database/migrations/*.php') ?: [] as $file) {
        $files[] = basename($file, '.php');
    }
    sort($files);

    $pending = array_values(array_diff($files, $ran));

    return [
        'ok' => $pending === [],
        'table_exists' => true,
        'ran' => count($ran),
        'total' => count($files),
        'pending' => $pending,
    ];
}

/**
 * @return array<string, mixed>
 */
function health_storage(string $root): array
{
    $dirs = [
        'logs' => $root.'/storage/logs',
        'cache' => $root.'/storage/framework/cache',
        'sessions' => $root.'/storage/framework/sessions',
        'views' => $root.'/storage/framework/views',
        'app_public' => $root.'/storage/app/public',
    ];

    $states = [];
    foreach ($dirs as $label => $path) {
        $states[$label] = ['path' => $path, 'writable' => health_writable($path)];
    }

    // Same tolerance as setup.php / the Laravel health route: a real symlink on
    // Linux, or file_exists() on Windows dev boxes where the link is a git-bash
    // artifact that PHP reports as neither a link nor a dir.
    $link = $root.'/public/storage';
    $linkOk = is_link($link) || is_dir($link) || file_exists($link);

    return [
        'ok' => $linkOk && count(array_filter($states, fn (array $state) => ! $state['writable'])) === 0,
        'public_storage_link' => $linkOk,
        'directories' => $states,
    ];
}

function health_writable(string $dir): bool
{
    if (! is_dir($dir)) {
        return false;
    }

    $probe = $dir.'/.health-'.bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'ok') === false) {
        return false;
    }
    @unlink($probe);

    return true;
}

/**
 * Expose the tail of laravel.log — the direct answer to "logs not generated".
 * Not part of the overall status (production apps legitimately have no log file
 * until the first warning/error); the logs dir writability above covers it.
 *
 * @return array<string, mixed>
 */
function health_logs(string $root, bool $logsDirWritable): array
{
    $path = $root.'/storage/logs/laravel.log';

    if (is_file($path)) {
        $lines = file($path) ?: [];

        return [
            'ok' => true,
            'exists' => true,
            'path' => $path,
            'size_bytes' => filesize($path) ?: 0,
            'modified_at' => gmdate('c', filemtime($path) ?: 0),
            'last_50_lines' => implode('', array_slice($lines, -50)),
        ];
    }

    return [
        'ok' => $logsDirWritable,
        'exists' => false,
        'path' => $path,
        'detail' => $logsDirWritable
            ? 'No log file yet — the app has not logged since deploy (normal in production until the first warning/error); storage/logs is writable.'
            : 'No log file AND storage/logs is not writable — logging cannot work until the permissions are fixed.',
    ];
}

/**
 * Probe where uploaded reels actually land. With VIDEO_DISK=ftp the file goes
 * to the FTP server (FTP_ROOT), so the local storage checks can be fine while
 * every upload silently fails (Laravel's throw=false swallows the write error).
 * Connects, logs in, and writes+deletes a probe file directly inside FTP_ROOT.
 * Never echoes credentials; connection attempts are capped at 10s.
 *
 * @return array<string, mixed>
 */
function health_video_disk(string $root, array $env): array
{
    $disk = $env['VIDEO_DISK'] ?? 'public';

    if ($disk !== 'ftp') {
        return [
            'ok' => true,
            'disk' => $disk,
            'detail' => 'Files are stored on the local "'.$disk.'" disk (storage/app/public) — served via the public/storage link. Not probed over FTP.',
        ];
    }

    $host = $env['FTP_HOST'] ?? '';
    if ($host === '') {
        return [
            'ok' => false,
            'disk' => 'ftp',
            'detail' => 'VIDEO_DISK=ftp but FTP_HOST is not set in .env — uploads cannot work. Add the FTP_* variables or switch VIDEO_DISK=public.',
        ];
    }

    $port = (int) ($env['FTP_PORT'] ?? 21);
    $username = $env['FTP_USERNAME'] ?? '';
    $password = $env['FTP_PASSWORD'] ?? '';
    $rootDir = rtrim($env['FTP_ROOT'] ?? '/', '/');
    $ssl = ($env['FTP_SSL'] ?? 'false') === 'true';

    $conn = $ssl ? @ftp_ssl_connect($host, $port, 10) : @ftp_connect($host, $port, 10);
    if ($conn === false) {
        return [
            'ok' => false,
            'disk' => 'ftp',
            'detail' => 'FTP connection to '.$host.':'.$port.' failed — check FTP_HOST/FTP_PORT and that FTP access is enabled. (Credentials are never echoed.)',
        ];
    }

    try {
        if (@ftp_login($conn, $username, $password) === false) {
            return ['ok' => false, 'disk' => 'ftp', 'detail' => 'FTP login failed — check FTP_USERNAME/FTP_PASSWORD. (Credentials are never echoed.)'];
        }

        $probe = '.health-'.bin2hex(random_bytes(4));
        $target = ($rootDir === '' ? '' : $rootDir.'/').$probe;
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, 'ok');
        rewind($handle);
        $written = @ftp_fput($conn, $target, $handle, FTP_BINARY);
        fclose($handle);

        if ($written === false) {
            return [
                'ok' => false,
                'disk' => 'ftp',
                'detail' => 'Could not write a probe file to FTP_ROOT ('.$rootDir.'). The folder may not exist or may not be writable — create it in hPanel and make sure FTP_ROOT matches the FTP account home (a common Hostinger mismatch).',
            ];
        }

        @ftp_delete($conn, $target);

        return ['ok' => true, 'disk' => 'ftp', 'detail' => 'FTP login OK and a probe file was written to and deleted from FTP_ROOT ('.$rootDir.').'];
    } catch (Throwable $e) {
        return ['ok' => false, 'disk' => 'ftp', 'detail' => 'FTP probe error: '.$e->getMessage()];
    } finally {
        @ftp_close($conn);
    }
}

// ---------------------------------------------------------------------------
// Assemble the report
// ---------------------------------------------------------------------------

$env = health_env($root);

// Video disk — where uploaded reels actually land. With VIDEO_DISK=ftp the
// file goes to the FTP server (FTP_ROOT), so the storage checks above can be
// fine while every upload silently fails (throw=false swallows the write
// error). Probe the real destination.
$checks['video_disk'] = health_video_disk($root, $env);

// PHP (the web SAPI — this file running is proof of at least that).
$missingExtensions = [];
foreach (['pdo', 'mbstring', 'openssl', 'curl', 'fileinfo'] as $ext) {
    if (! extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

$checks['php'] = [
    'ok' => version_compare(PHP_VERSION, '8.3', '>=') && $missingExtensions === [],
    'version' => PHP_VERSION,
    'required' => '>= 8.3',
    'sapi' => PHP_SAPI,
    'exec_disabled' => in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
    'missing_extensions' => $missingExtensions,
];

// .env presence + APP_KEY (the classic post-deploy 500 cause).
$checks['env'] = [
    'ok' => $env !== [] && ($env['APP_KEY'] ?? '') !== '',
    'file_exists' => $env !== [],
    'app_key_set' => ($env['APP_KEY'] ?? '') !== '',
    'app_env' => $env['APP_ENV'] ?? '(missing)',
    'app_debug' => ($env['APP_DEBUG'] ?? '') === 'true',
];

$dbResult = health_db($root, $env);
$checks['database'] = $dbResult['info'];

$checks['migrations'] = health_migrations($root, $dbResult['pdo']);

$checks['storage'] = health_storage($root);

$checks['logs'] = health_logs($root, (bool) ($checks['storage']['directories']['logs']['writable'] ?? false));

$ok = $checks['php']['ok']
    && $checks['env']['ok']
    && $checks['database']['ok']
    && $checks['migrations']['ok']
    && $checks['storage']['ok']
    && $checks['video_disk']['ok'];

http_response_code($ok ? 200 : 503);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'healthy' : 'degraded',
    'generated_at' => gmdate('c'),
    'probe' => 'standalone (Laravel not booted)',
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
