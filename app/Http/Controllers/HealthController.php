<?php

namespace App\Http\Controllers;

use App\Services\DeployService;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public diagnostics endpoint. Reports the four things that make a shared-host
 * 500 a mystery — PHP version, DB connectivity, migration state, and storage
 * writability — plus a real log-write probe (logging failing silently is the
 * usual reason a 500 stays a mystery). Every check is isolated in its own
 * try/catch so the endpoint itself never 500s: on any failing check it answers
 * 503 with the failing checks spelled out.
 *
 * When the framework itself cannot boot (missing .env, empty APP_KEY, dead
 * config cache), no Laravel route can run — public/health.php reports the same
 * diagnostics standalone, without Laravel.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $php = $this->checkPhp();
        $database = $this->checkDatabase();
        $migrations = $this->checkMigrations();
        $storage = $this->checkStorage();

        $ok = $php['ok'] && $database['ok'] && $migrations['ok'] && $storage['ok'];

        return response()->json([
            'ok' => $ok,
            'status' => $ok ? 'healthy' : 'degraded',
            'generated_at' => now()->toIso8601String(),
            'app' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'debug' => (bool) config('app.debug'),
                'url' => config('app.url'),
                'laravel' => app()->version(),
                'config_cached' => app()->configurationIsCached(),
                'routes_cached' => app()->routesAreCached(),
            ],
            'php' => $php,
            'database' => $database,
            'migrations' => $migrations,
            'storage' => $storage,
        ], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @return array{ok: bool, version: string, required: string, sapi: string, cli_binary: string, exec_disabled: bool, missing_extensions: list<string>}
     */
    private function checkPhp(): array
    {
        $required = ['pdo', 'mbstring', 'openssl', 'curl', 'fileinfo'];
        $missing = array_values(array_filter($required, fn (string $ext) => ! extension_loaded($ext)));
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return [
            'ok' => version_compare(PHP_VERSION, '8.3', '>=') && $missing === [],
            'version' => PHP_VERSION,
            'required' => '>= 8.3',
            'sapi' => PHP_SAPI,
            'cli_binary' => DeployService::phpBinary(),
            'exec_disabled' => in_array('exec', $disabled, true),
            'missing_extensions' => $missing,
        ];
    }

    /**
     * @return array{ok: bool, connected: bool, driver: string, database?: string, error?: string}
     */
    private function checkDatabase(): array
    {
        $driver = (string) config('database.default');

        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return [
                'ok' => true,
                'connected' => true,
                'driver' => $driver,
                'database' => (string) config('database.connections.'.$driver.'.database'),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'connected' => false,
                'driver' => $driver,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, table_exists?: bool, ran?: int, total?: int, pending?: list<string>, detail?: string, error?: string}
     */
    private function checkMigrations(): array
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');
            $repository = $migrator->getRepository();

            if (! $repository->repositoryExists()) {
                return [
                    'ok' => false,
                    'table_exists' => false,
                    'detail' => 'The migrations table is missing — run "php artisan migrate --force" (or open public/setup.php).',
                ];
            }

            // getRan() returns a plain list of migration names (not a Collection).
            $ran = $repository->getRan();
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            $pending = array_values(array_diff($files, $ran));

            return [
                'ok' => $pending === [],
                'table_exists' => true,
                'ran' => count($ran),
                'total' => count($files),
                'pending' => $pending,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, public_storage_link: bool, log_probe: array<string, mixed>, directories: array<string, array{path: string, writable: bool}>}
     */
    private function checkStorage(): array
    {
        $directories = [
            'logs' => storage_path('logs'),
            'cache' => storage_path('framework/cache'),
            'sessions' => storage_path('framework/sessions'),
            'views' => storage_path('framework/views'),
            'app_public' => storage_path('app/public'),
        ];

        $states = [];
        foreach ($directories as $label => $path) {
            $states[$label] = ['path' => $path, 'writable' => $this->isWritable($path)];
        }

        // Same tolerance as setup.php/storage:link: a real symlink on Linux, or
        // file_exists() on Windows dev boxes where the link is a git-bash artifact.
        $linkExists = is_link(public_path('storage')) || file_exists(public_path('storage'));
        $logProbe = $this->logProbe();

        return [
            'ok' => $linkExists
                && $logProbe['ok']
                && count(array_filter($states, fn (array $state) => ! $state['writable'])) === 0,
            'public_storage_link' => $linkExists,
            'log_probe' => $logProbe,
            'directories' => $states,
        ];
    }

    private function isWritable(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }

        $probe = $dir.'/.health-'.bin2hex(random_bytes(4));

        try {
            if (@file_put_contents($probe, 'ok') === false) {
                return false;
            }
            @unlink($probe);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Write a real log line through the configured channel and confirm it
     * landed in the log file — the definitive answer to "logs not generated".
     *
     * @return array{ok: bool, channel?: string, path?: string, error?: string}
     */
    private function logProbe(): array
    {
        try {
            $channel = (string) config('logging.default');
            $probe = 'health-probe-'.bin2hex(random_bytes(4));

            Log::channel($channel)->info('Health probe: '.$probe);

            $path = (string) config('logging.channels.single.path', storage_path('logs/laravel.log'));
            $content = is_file($path) ? (string) file_get_contents($path) : '';

            return [
                'ok' => str_contains($content, $probe),
                'channel' => $channel,
                'path' => $path,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
