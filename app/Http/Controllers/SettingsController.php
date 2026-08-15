<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\DeployService;
use App\Services\GeminiVideoAnalyzer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index()
    {
        $analyzer = app(GeminiVideoAnalyzer::class);

        return view('settings.index', [
            'geminiEnabled' => $analyzer->enabled(),
            'geminiModel' => $analyzer->model(),
            // Only "saved" when the stored key actually decrypts (a stale
            // ciphertext from a changed APP_KEY reads back as empty).
            'geminiHasApiKey' => filled($analyzer->apiKey()),
            'geminiModels' => GeminiVideoAnalyzer::MODELS,
            'channels' => Auth::user()->channels()->orderBy('name')->get(),
            // Scheduler / cron job state.
            'cronEnabled' => Setting::get('cron.enabled', '1') === '1',
            'cronPublishEnabled' => Setting::get('cron.publish_enabled', '1') === '1',
            'cronAnalyticsEnabled' => Setting::get('cron.analytics_enabled', '1') === '1',
            'cronPruneEnabled' => Setting::get('cron.prune_enabled', '1') === '1',
            'lastCronCheckAt' => Setting::get('cron.last_checked') ? Carbon::parse(Setting::get('cron.last_checked')) : null,
            'cronLastRuns' => [
                'publish' => Setting::get('cron.last_run.publish') ? Carbon::parse(Setting::get('cron.last_run.publish')) : null,
                'analytics' => Setting::get('cron.last_run.analytics') ? Carbon::parse(Setting::get('cron.last_run.analytics')) : null,
                'prune' => Setting::get('cron.last_run.prune') ? Carbon::parse(Setting::get('cron.last_run.prune')) : null,
            ],
            // Exact crontab line to paste on Linux / Hostinger (cPanel Cron Jobs).
            'cronLine' => '* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1',
            // One-click post-deploy setup status.
            'deploy' => $this->deployStatus(),
        ]);
    }

    /**
     * Cheap, side-effect-free checks shown next to the Run Deployment Setup
     * button. null for migrations means the DB is unreachable right now.
     */
    private function deployStatus(): array
    {
        try {
            $migrations = Schema::hasTable('migrations');
        } catch (\Throwable) {
            $migrations = null;
        }

        return [
            'has_key' => filled(config('app.key')),
            'has_storage_link' => file_exists(public_path('storage')),
            'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
            'migrations' => $migrations,
        ];
    }

    /**
     * One-click post-deploy setup: key:generate (only if missing), migrate
     * --force, storage:link, optimize. Each step runs in its own subprocess so
     * a freshly generated key is picked up before config caching.
     */
    public function runDeploy(Request $request)
    {
        // Migrations / caching can outlive the default request time cap.
        @set_time_limit(0);

        $runner = app(DeployService::class);
        $results = [];
        $keyGenerated = false;

        // 1. Encryption key — only generated when missing. Regenerating an
        // existing key would invalidate every encrypted value (secrets, sessions).
        if (filled(config('app.key'))) {
            $results[] = ['name' => 'Encryption key', 'status' => 'skipped', 'detail' => 'APP_KEY already set — keeping it.'];
        } else {
            $run = $runner->runArtisan(['key:generate', '--force']);
            $keyGenerated = $run['exit'] === 0;
            $results[] = ['name' => 'Encryption key', 'status' => $keyGenerated ? 'done' : 'failed', 'detail' => $this->summarizeRun($run)];
        }

        // 2. Database tables (idempotent — safe to re-run).
        $run = $runner->runArtisan(['migrate', '--force']);
        $results[] = ['name' => 'Database migrations', 'status' => $run['exit'] === 0 ? 'done' : 'failed', 'detail' => $this->summarizeRun($run)];

        // 3. Public storage link (idempotent).
        $run = $runner->runArtisan(['storage:link']);
        $results[] = ['name' => 'Storage link', 'status' => $run['exit'] === 0 ? 'done' : 'failed', 'detail' => $this->summarizeRun($run)];

        // 4. Cache config, routes, views, events.
        $run = $runner->runArtisan(['optimize']);
        $results[] = ['name' => 'Config / route / view cache', 'status' => $run['exit'] === 0 ? 'done' : 'failed', 'detail' => $this->summarizeRun($run)];

        $failed = collect($results)->contains(fn ($r) => $r['status'] === 'failed');

        // A freshly generated key invalidates every session — sign back in.
        if ($keyGenerated) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return back()
            ->with('deploy_results', $results)
            ->with($failed ? 'error' : 'success', $failed
                ? 'Some deployment setup steps failed — see details below.'
                : 'Deployment setup complete.');
    }

    /**
     * Collapse the command output into a short single-line summary.
     */
    private function summarizeRun(array $run): string
    {
        $output = $run['output'] !== '' ? preg_replace('/\s+/', ' ', $run['output']) : null;

        return $output ? Str::limit($output, 180) : ($run['exit'] === 0 ? 'OK' : 'Command failed');
    }

    public function saveCron(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'publish_enabled' => ['nullable', 'boolean'],
            'analytics_enabled' => ['nullable', 'boolean'],
            'prune_enabled' => ['nullable', 'boolean'],
        ]);

        Setting::set('cron.enabled', $request->boolean('enabled') ? '1' : '0');
        Setting::set('cron.publish_enabled', $request->boolean('publish_enabled') ? '1' : '0');
        Setting::set('cron.analytics_enabled', $request->boolean('analytics_enabled') ? '1' : '0');
        Setting::set('cron.prune_enabled', $request->boolean('prune_enabled') ? '1' : '0');

        return back()->with('success', 'Cron job settings saved.');
    }

    /**
     * Create / refresh the OS-level scheduler entry (Windows Task or
     * crontab) — re-running is idempotent and picks up any path changes.
     */
    public function installCron()
    {
        $exitCode = Artisan::call('cron:install');
        $output = trim(Artisan::output());

        return back()->with($exitCode === 0 ? 'success' : 'error', $output ?: 'Cron install finished.');
    }

    /**
     * Remove the OS-level scheduler entry. App-level toggles above are not
     * touched — this only stops the OS from calling schedule:run.
     */
    public function uninstallCron()
    {
        $exitCode = Artisan::call('cron:uninstall');
        $output = trim(Artisan::output());

        return back()->with($exitCode === 0 ? 'success' : 'error', $output ?: 'Cron uninstall finished.');
    }

    public function saveGemini(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            // Free-form model name — users type any Gemini model (incl. previews).
            'model' => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'remove_api_key' => ['nullable', 'boolean'],
            // Per-channel overrides: one channel can use Gemini, another not.
            'channel_overrides' => ['nullable', 'array'],
            'channel_overrides.*' => ['nullable', 'in:default,enabled,disabled'],
        ]);

        // The key is never echoed back: blank keeps the saved one unless the
        // user explicitly checks "remove".
        $apiKey = $validated['api_key'] ?? null;
        if (filled($apiKey)) {
            Setting::set('gemini.api_key', Crypt::encryptString($apiKey));
        } elseif ($request->boolean('remove_api_key')) {
            Setting::forget('gemini.api_key');
        }

        Setting::set('gemini.model', $validated['model']);
        Setting::set('gemini.enabled', $request->boolean('enabled') ? '1' : '0');

        // Apply per-channel overrides (only for channels owned by this user).
        $overrides = $validated['channel_overrides'] ?? [];
        foreach (Auth::user()->channels()->get() as $channel) {
            $channel->setGeminiOverride((string) ($overrides[(string) $channel->id] ?? 'default'));
        }

        return back()->with('success', 'Gemini AI settings saved.');
    }

    public function testGemini()
    {
        return response()->json(app(GeminiVideoAnalyzer::class)->testConnection());
    }
}
