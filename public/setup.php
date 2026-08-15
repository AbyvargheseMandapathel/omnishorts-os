<?php

declare(strict_types=1);

/**
 * public/setup.php — emergency post-deploy setup page.
 *
 * Runs even when the Laravel app itself cannot boot (missing .env, empty
 * APP_KEY, missing DB tables) and performs the standard post-deploy steps:
 *
 *   1. key:generate  — writes APP_KEY into .env directly (pure PHP; only when
 *                      a key is missing — never overwrites an existing key)
 *   2. migrate       — php artisan migrate --force (via the PHP CLI)
 *   3. storage:link  — creates public/storage -> storage/app/public (pure PHP)
 *   4. optimize      — php artisan optimize (via the PHP CLI)
 *
 * SECURITY
 *   - Token-protected. The token comes from the SETUP_TOKEN environment
 *     variable, a SETUP_TOKEN= line in .env, or the SETUP_TOKEN constant
 *     below (change it from __CHANGE_ME__). With no token configured the
 *     page refuses to do anything.
 *   - Self-deletes after a fully successful run. To bring it back later:
 *     `git checkout public/setup.php` (or re-upload the file).
 *   - This is a temporary repair tool — never leave it on a production
 *     server after setup.
 */
const SETUP_TOKEN = '__CHANGE_ME__';

set_time_limit(300);
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function setup_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function setup_root(): string
{
    return dirname(__DIR__);
}

/**
 * Token sources, in priority order: server env, a SETUP_TOKEN= line in .env,
 * then the SETUP_TOKEN constant (only if changed from the placeholder).
 */
function setup_resolve_token(): ?string
{
    $env = getenv('SETUP_TOKEN');
    if (is_string($env) && $env !== '') {
        return $env;
    }

    $envFile = setup_root().'/.env';
    if (is_file($envFile)) {
        $lines = preg_split('/\r?\n/', (string) file_get_contents($envFile)) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^SETUP_TOKEN\s*=\s*(.+?)\s*$/', trim($line), $m) && $m[1] !== '') {
                return trim($m[1], "\"'");
            }
        }
    }

    if (SETUP_TOKEN !== '' && SETUP_TOKEN !== '__CHANGE_ME__') {
        return SETUP_TOKEN;
    }

    return null;
}

/**
 * Run an artisan command via the PHP CLI. Returns [ok, output].
 *
 * @param  list<string>  $arguments
 * @return array{0: bool, 1: string}
 */
/**
 * Find a PHP CLI binary that can run the app (>= 8.3). On shared hosts the
 * web SAPI can be newer than the CLI "php" on PATH (Hostinger: web 8.3, CLI
 * 8.2) — Composer's platform check then kills artisan. Returns [binary, version].
 *
 * @return array{binary: string, version: string}
 */
function setup_detect_php(): array
{
    static $detected = null;
    if ($detected !== null) {
        return $detected;
    }

    $candidates = array_values(array_unique(array_filter([
        PHP_BINARY,
        'php8.4', 'php84', 'php8.3', 'php83', 'php',
    ], fn (string $candidate) => $candidate !== '')));

    foreach ($candidates as $candidate) {
        $output = [];
        $code = 1;
        @exec(escapeshellarg($candidate).' -r '.escapeshellarg('echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;').' 2>&1', $output, $code);
        $version = trim((string) ($output[0] ?? ''));
        if ($code === 0 && preg_match('/^\d+\.\d+$/', $version) && version_compare($version, '8.3', '>=')) {
            return $detected = ['binary' => $candidate, 'version' => $version];
        }
    }

    return $detected = ['binary' => PHP_BINARY, 'version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION];
}

function setup_cli(array $arguments): array
{
    $disabled = in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    if ($disabled) {
        return [false, 'The PHP exec() function is disabled on this server — run "php artisan '.implode(' ', $arguments).'" manually from the terminal.'];

    }

    $command = 'cd '.escapeshellarg(setup_root())
        .' && '.escapeshellarg(setup_detect_php()['binary'])
        .' artisan '.implode(' ', array_map('escapeshellarg', $arguments))
        .' 2>&1';

    $output = [];
    $code = 0;
    exec($command, $output, $code);

    return [$code === 0, trim(implode("\n", $output))];
}

/**
 * Run all four setup steps. Each result: ['name', 'status' (ok|failed), 'detail', 'output'].
 *
 * @return list<array{name: string, status: string, detail: string, output: string}>
 */
function setup_run_steps(): array
{
    $results = [];
    $root = setup_root();
    $envFile = $root.'/.env';

    // --- 1. Encryption key (pure PHP, never overwrites an existing key) -----
    $hasKey = false;
    if (is_file($envFile)) {
        // \s would cross line breaks; use horizontal whitespace only.
        $hasKey = preg_match('/^APP_KEY[ \t]*=[ \t]*([^\r\n]*)$/m', (string) file_get_contents($envFile), $m) === 1 && trim($m[1]) !== '';
    }

    if ($hasKey) {
        $results[] = ['name' => 'Encryption key (APP_KEY)', 'status' => 'ok', 'detail' => 'Already set — keeping it. Never overwrites an existing key.', 'output' => ''];
    } elseif (! is_file($envFile)) {
        $results[] = ['name' => 'Encryption key (APP_KEY)', 'status' => 'failed', 'detail' => 'No .env file found in '.$root.' — create it first (copy .env.example and fill in the values).', 'output' => ''];
    } elseif (! is_writable($envFile)) {
        $results[] = ['name' => 'Encryption key (APP_KEY)', 'status' => 'failed', 'detail' => '.env is not writable by PHP — fix permissions (e.g. "chmod 664 .env").', 'output' => ''];
    } else {
        $key = 'base64:'.base64_encode(random_bytes(32));
        $lines = preg_split('/\r?\n/', (string) file_get_contents($envFile)) ?: [];
        $replaced = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^APP_KEY\s*=/', $line)) {
                $lines[$i] = 'APP_KEY='.$key;
                $replaced = true;
                break;
            }
        }
        if (! $replaced) {
            $lines[] = 'APP_KEY='.$key;
        }
        $written = file_put_contents($envFile, implode(PHP_EOL, $lines).PHP_EOL);
        if ($written === false) {
            $results[] = ['name' => 'Encryption key (APP_KEY)', 'status' => 'failed', 'detail' => 'Could not write to .env.', 'output' => ''];
        } else {
            $results[] = ['name' => 'Encryption key (APP_KEY)', 'status' => 'ok', 'detail' => 'Generated and written to .env.', 'output' => $key];
        }
    }

    // --- 2. Database migrations --------------------------------------------
    [$ok, $output] = setup_cli(['migrate', '--force']);
    $results[] = ['name' => 'Database migrations', 'status' => $ok ? 'ok' : 'failed', 'detail' => $ok ? 'Migrations completed.' : 'Migrations failed — see output below.', 'output' => $output];

    // --- 3. Storage link (pure PHP) ----------------------------------------
    $target = $root.'/storage/app/public';
    $link = __DIR__.'/storage';
    if (is_link($link) || file_exists($link)) {
        $results[] = ['name' => 'Storage link', 'status' => 'ok', 'detail' => 'public/storage already exists.', 'output' => ''];
    } else {
        if (! is_dir($target)) {
            @mkdir($target, 0755, true);
        }
        if (@symlink($target, $link)) {
            $results[] = ['name' => 'Storage link', 'status' => 'ok', 'detail' => 'Created public/storage → storage/app/public.', 'output' => ''];
        } else {
            $err = error_get_last();
            $results[] = ['name' => 'Storage link', 'status' => 'failed', 'detail' => 'symlink() failed'.($err ? ': '.$err['message'] : '').' — create it manually: ln -s ../storage/app/public public/storage', 'output' => ''];
        }
    }

    // --- 4. Optimize --------------------------------------------------------
    [$ok, $output] = setup_cli(['optimize']);
    $results[] = ['name' => 'Config / route / view cache', 'status' => $ok ? 'ok' : 'failed', 'detail' => $ok ? 'Caches built.' : 'optimize failed — see output below.', 'output' => $output];

    return $results;
}

/**
 * Cheap checks shown before running.
 *
 * @return list<array{label: string, ok: bool}>
 */
function setup_check_status(): array
{
    $envFile = setup_root().'/.env';

    return [
        ['label' => '.env file exists', 'ok' => is_file($envFile)],
        ['label' => 'APP_KEY is set', 'ok' => is_file($envFile) && preg_match('/^APP_KEY[ \t]*=[ \t]*([^\r\n]*)$/m', (string) file_get_contents($envFile), $m) === 1 && trim($m[1]) !== ''],
        ['label' => 'public/storage link exists', 'ok' => is_link(__DIR__.'/storage') || file_exists(__DIR__.'/storage')],
        ['label' => 'PHP exec() available (needed for migrate/optimize)', 'ok' => ! in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)],
    ];
}

function setup_page(string $heading, string $innerHtml): void
{
    echo '<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Deployment Setup</title>
<style>
body{background:#0d1117;color:#e6edf3;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;padding:40px 16px}
.wrap{max-width:680px;margin:0 auto}
.card{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:24px}
h1{font-size:1.25rem;margin:0 0 6px}
p.sub{color:#9ca3af;font-size:.85rem;margin:0 0 18px}
label{display:block;font-size:.8rem;font-weight:600;margin-bottom:6px}
input[type=password]{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:8px;border:1px solid #30363d;background:#0d1117;color:#e6edf3;font-size:.9rem}
button{margin-top:14px;padding:9px 18px;border-radius:8px;border:0;background:#238636;color:#fff;font-weight:600;font-size:.85rem;cursor:pointer}
button.danger{background:#da3633}
.row{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid #21262d;font-size:.85rem}
.row:last-child{border-bottom:0}
.st{flex-shrink:0;width:16px;text-align:center}
.name{flex:1;min-width:0}
.detail{display:block;color:#9ca3af;font-size:.75rem;margin-top:2px}
pre{background:#0d1117;border:1px solid #30363d;border-radius:8px;padding:10px;font-size:.72rem;overflow-x:auto;max-height:180px;margin:6px 0 0;color:#a5d6ff}
.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:16px}
.actions form{margin:0}
.note{font-size:.75rem;color:#9ca3af;margin-top:14px;line-height:1.5}
code{background:rgba(255,255,255,.07);padding:1px 5px;border-radius:4px;font-size:.78em}
</style></head><body><div class="wrap"><div class="card">
<h1>'.setup_h($heading).'</h1>'.$innerHtml.'
</div></div></body></html>';
}

// ---------------------------------------------------------------------------
// Request flow
// ---------------------------------------------------------------------------

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$givenToken = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
$configuredToken = setup_resolve_token();
$authorized = $configuredToken !== null && $givenToken !== '' && hash_equals($configuredToken, $givenToken);

// Wrong-token attempts get a plain 403 (no information leak).
if ($givenToken !== '' && ! $authorized && $method === 'POST') {
    http_response_code(403);
    setup_page('Access denied', '<p style="color:#f87171;font-size:.9rem">The token you entered is incorrect.</p>');
    exit;
}

// No token configured at all — refuse to do anything.
if ($configuredToken === null) {
    setup_page('Setup is locked', '<p style="font-size:.9rem;color:#f0b429">No <code>SETUP_TOKEN</code> is configured, so this page refuses to run.</p>
        <p class="note">Set a token by adding <code>SETUP_TOKEN=your-secret</code> to your <code>.env</code> (or the server environment), or edit the <code>SETUP_TOKEN</code> constant at the top of <code>public/setup.php</code>.</p>');
    exit;
}

// Token form.
if (! $authorized) {
    setup_page('Deployment setup', '<p class="sub">This page can run <code>key:generate</code>, <code>migrate --force</code>, <code>storage:link</code> and <code>optimize</code> even when the app cannot boot. Enter the setup token to continue.</p>
        <form method="post"><label for="tok">Setup token</label>
        <input type="password" name="token" id="tok" autocomplete="off" required>
        <button type="submit">Continue</button></form>');
    exit;
}

// --- Authorized ------------------------------------------------------------

$action = (string) ($_POST['action'] ?? '');
$allSucceeded = true;
$results = [];

$ok = 'background: rgba(16,185,129,.14); color:#34d399; border:1px solid rgba(16,185,129,.35)';
$bad = 'background: rgba(239,68,68,.14); color:#f87171; border:1px solid rgba(239,68,68,.35)';
$dim = 'background: rgba(255,255,255,.06); color:#9ca3af; border:1px solid rgba(255,255,255,.14)';

$phpInfo = setup_detect_php();
$phpOk = version_compare($phpInfo['version'], '8.3', '>=');
$phpNote = '<div class="row"><span class="st" style="color:'.($phpOk ? '#34d399' : '#f87171').'">'.($phpOk ? '✔' : '✗').'</span><div class="name">PHP CLI for artisan steps: <code>'.setup_h($phpInfo['binary']).'</code> (PHP '.setup_h($phpInfo['version']).')'.($phpOk ? '' : '<span class="detail">Too old for Laravel 13 — install/enable a PHP 8.3+ CLI (Hostinger: hPanel → PHP Configuration, or use php8.3/php8.4).</span>').'</div></div>';

if ($action === 'run') {
    // Prevent two concurrent runs (e.g. double-click) from racing.
    $lockPath = sys_get_temp_dir().'/omnishorts-setup-'.md5(setup_root()).'.lock';
    $lock = fopen($lockPath, 'c');
    $acquired = $lock !== false && flock($lock, LOCK_EX | LOCK_NB);
    if (! $acquired) {
        setup_page('Deployment setup', '<p style="font-size:.9rem;color:#f0b429">Another setup run is already in progress. Refresh to check the result.</p>');
        exit;
    }

    $results = setup_run_steps();
    $allSucceeded = true;
    foreach ($results as $result) {
        if ($result['status'] !== 'ok') {
            $allSucceeded = false;
            break;
        }
    }

    if ($allSucceeded) {
        @unlink(__FILE__); // self-delete: the repair tool's job is done
    }

    fclose($lock);
}

if ($action === 'delete') {
    @unlink(__FILE__);
    setup_page('setup.php removed', '<p style="font-size:.9rem">The <code>public/setup.php</code> repair file has been deleted. Your app is now the only entry point. To restore this tool later: <code>git checkout public/setup.php</code>.</p>');
    exit;
}

$statusHtml = '';
foreach (setup_check_status() as $check) {
    $style = $check['ok'] ? $ok : $bad;
    $statusHtml .= '<div class="row"><span class="st" style="color:'.($check['ok'] ? '#34d399' : '#f87171').'">'.($check['ok'] ? '✔' : '✗').'</span><div class="name">'.setup_h($check['label']).'</div></div>';
}

$resultsHtml = '';
if (! empty($results)) {
    foreach ($results as $result) {
        $style = $result['status'] === 'ok' ? $ok : ($result['status'] === 'failed' ? $bad : $dim);
        $symbol = $result['status'] === 'ok' ? '✔' : ($result['status'] === 'failed' ? '✗' : '–');
        $color = $result['status'] === 'ok' ? '#34d399' : ($result['status'] === 'failed' ? '#f87171' : '#9ca3af');
        $resultsHtml .= '<div class="row" style="border-color:#30363d"><span class="st" style="color:'.$color.'">'.$symbol.'</span><div class="name">'.setup_h($result['name']).'<span class="detail">'.setup_h($result['detail']).'</span>'
            .($result['output'] !== '' ? '<pre>'.setup_h($result['output']).'</pre>' : '')
            .'</div></div>';
    }
}

if ($allSucceeded && $action === 'run') {
    $heading = 'Setup complete';
    $inner = '<p class="sub" style="color:#34d399">All steps succeeded — <code>public/setup.php</code> has deleted itself. Load your site now.</p>'.$resultsHtml;
} elseif (! $allSucceeded && $action === 'run') {
    $heading = 'Setup finished with errors';
    $inner = '<p class="sub" style="color:#f87171">Not all steps succeeded — the file was kept so you can retry. Fix the failing step(s), then run again.</p>'.$resultsHtml
        .'<div class="actions">
            <form method="post"><input type="hidden" name="token" value="'.setup_h($givenToken).'"><input type="hidden" name="action" value="run"><button type="submit">Retry setup</button></form>
            <form method="post"><input type="hidden" name="token" value="'.setup_h($givenToken).'"><input type="hidden" name="action" value="delete"><button type="submit" class="danger">Delete setup.php anyway</button></form>
        </div>';
} else {
    $heading = 'Deployment setup';
    $inner = '<p class="sub">Current state before running:</p>'.$phpNote.$statusHtml
        .'<div class="actions">
            <form method="post"><input type="hidden" name="token" value="'.setup_h($givenToken).'"><input type="hidden" name="action" value="run"><button type="submit">⚡ Run deployment setup</button></form>
            <form method="post"><input type="hidden" name="token" value="'.setup_h($givenToken).'"><input type="hidden" name="action" value="delete"><button type="submit" class="danger">Delete setup.php</button></form>
        </div>
        <p class="note">Steps: <code>key:generate</code> (only if missing) · <code>migrate --force</code> · <code>storage:link</code> · <code>optimize</code>. The page deletes itself after a fully successful run.</p>';
}

setup_page($heading, $inner);
