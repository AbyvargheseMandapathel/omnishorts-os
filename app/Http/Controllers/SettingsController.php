<?php

namespace App\Http\Controllers;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Models\Setting;
use App\Services\Ai\ConnectionTester;
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
            // Uses a detected PHP >= 8.3 binary — the CLI "php" on shared hosts
            // can be older than the web SAPI and would fail the platform check.
            'cronLine' => '* * * * * cd '.base_path().' && '.DeployService::phpBinary().' artisan schedule:run >> /dev/null 2>&1',
            // One-click post-deploy setup status.
            'deploy' => $this->deployStatus(),
            // AI Connections + per content-type provider config.
            'aiConnections' => Auth::user()->aiConnections()->with('contentTypeAssignments')->orderBy('name')->get(),
            'aiContentTypes' => config('ai.content_types'),
            'aiProviders' => config('ai.providers'),
            'aiRoles' => config('ai.roles'),
            'aiContentTypeConfigs' => AiContentTypeConfig::query()
                ->where('user_id', Auth::id())
                ->with('aiConnection')
                ->get()
                ->keyBy(fn ($c) => $c->content_type.':'.$c->role),
            'aiDefaultConfigs' => config('ai.defaults'),
            // Daily Auto-Generation settings + last run/error state.
            'aiDaily' => [
                'enabled' => Setting::get('ai.daily.enabled', '0') === '1',
                'time' => (string) (Setting::get('ai.daily.time') ?: config('ai.daily.time', '06:00')),
                'content_type' => (string) (Setting::get('ai.daily.content_type') ?: config('ai.daily.content_type', 'video')),
                'topics' => (string) Setting::get('ai.daily.topics', ''),
                'background_path' => (string) Setting::get('ai.daily.background_path', ''),
                'auto_approve' => Setting::get('ai.daily.auto_approve', '1') === '1',
                'last_run' => Setting::get('ai.daily.last_run') ?: null,
                'last_error' => Setting::get('ai.daily.last_error') ?: null,
            ],
        ]);
    }

    /**
     * Save the Daily Auto-Generation settings (topic pool, schedule, background,
     * auto-approve). The ai:generate-daily command reads these on every tick.
     */
    public function saveAiDaily(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'time' => ['required', 'date_format:H:i'],
            'content_type' => ['required', 'in:'.implode(',', config('ai.content_types'))],
            'topics' => ['nullable', 'string', 'max:4000'],
            'background_path' => ['nullable', 'string', 'max:300'],
            'auto_approve' => ['nullable', 'boolean'],
        ]);

        Setting::set('ai.daily.enabled', $request->boolean('enabled') ? '1' : '0');
        Setting::set('ai.daily.time', $validated['time']);
        Setting::set('ai.daily.content_type', $validated['content_type']);
        Setting::set('ai.daily.topics', trim((string) ($validated['topics'] ?? '')));
        Setting::set('ai.daily.background_path', trim((string) ($validated['background_path'] ?? '')));
        Setting::set('ai.daily.auto_approve', $request->boolean('auto_approve') ? '1' : '0');

        return back()->with('success', 'Daily Auto-Generation settings saved.');
    }

    /**
     * Create or update an AI connection. The API key is only written when a
     * new value is typed — blank keeps the saved (encrypted) one.
     */
    public function saveAiConnection(Request $request)
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', AiConnection::TYPES)],
            'provider' => ['required', 'string'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'model' => ['nullable', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'config' => ['nullable', 'string'],
            'content_types' => ['nullable', 'array'],
            'content_types.*' => ['in:'.implode(',', config('ai.content_types'))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $providerType = config("ai.providers.{$validated['provider']}.type");
        if (! $providerType || $providerType !== $validated['type']) {
            return back()->withErrors(['provider' => 'The selected provider does not match the chosen AI type.'])->withInput();
        }

        // Additional config must be valid JSON when provided.
        $extra = null;
        if (filled($validated['config'] ?? null)) {
            $decoded = json_decode($validated['config'], true);
            if (! is_array($decoded)) {
                return back()->withErrors(['config' => 'Additional configuration must be valid JSON (e.g. {"voice":"en-US-ChristopherNeural"}).'])->withInput();
            }
            $extra = $decoded;
        }

        $connection = filled($validated['id'] ?? null)
            ? Auth::user()->aiConnections()->findOrFail($validated['id'])
            : new AiConnection(['user_id' => Auth::id()]);

        $connection->fill([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'provider' => $validated['provider'],
            'model' => $validated['model'] ?? null,
            'base_url' => $validated['base_url'] ?? null,
            'config' => $extra,
            'is_active' => $request->boolean('is_active'),
        ]);

        // API key: only overwritten when a real value is typed; the "remove"
        // checkbox clears it explicitly.
        if (filled($validated['api_key'] ?? null)) {
            $connection->api_key = $validated['api_key'];
        } elseif ($request->boolean('remove_api_key')) {
            $connection->api_key = null;
        }

        $connection->save();
        $connection->syncContentTypes($validated['content_types'] ?? []);

        return back()->with('success', "AI connection '{$connection->name}' saved.");
    }

    public function deleteAiConnection(Request $request, AiConnection $connection)
    {
        abort_unless($connection->user_id === Auth::id(), 403);

        $name = $connection->name;
        $connection->delete();

        return back()->with('success', "AI connection '{$name}' deleted.");
    }

    /**
     * Save the per content-type primary/fallback provider selection. Only
     * connections the user owns are accepted.
     */
    public function saveAiContentTypeConfig(Request $request)
    {
        $contentTypes = config('ai.content_types');
        $roles = config('ai.roles');

        $validated = $request->validate([
            'configs' => ['nullable', 'array'],
            'configs.*.*' => ['nullable', 'integer'],
        ]);

        $owned = Auth::user()->aiConnections()->pluck('id')->all();

        foreach ($contentTypes as $contentType) {
            foreach ($roles as $role) {
                $connectionId = $validated['configs'][$contentType][$role] ?? null;
                $key = [$contentType, $role];

                if (! $connectionId) {
                    AiContentTypeConfig::query()
                        ->where('user_id', Auth::id())
                        ->where('content_type', $contentType)
                        ->where('role', $role)
                        ->delete();

                    continue;
                }

                if (! in_array((int) $connectionId, $owned, true)) {
                    continue;
                }

                AiContentTypeConfig::updateOrCreate(
                    ['user_id' => Auth::id(), 'content_type' => $contentType, 'role' => $role],
                    ['ai_connection_id' => (int) $connectionId]
                );
            }
        }

        return back()->with('success', 'Content Type AI configuration saved.');
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

    /**
     * Test an AI connection before saving it: builds the provider from the
     * form fields (an existing connection's saved key is used when the key
     * field is blank) and fires a tiny real request. Returns JSON
     * {ok: bool, message: string} — never echoes the key.
     */
    public function testAiConnection(Request $request)
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'type' => ['required', 'in:text,image,voice'],
            'provider' => ['required', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:2000'],
            'model' => ['nullable', 'string', 'max:200'],
            'base_url' => ['nullable', 'url', 'max:300'],
            'config' => ['nullable', 'string', 'max:2000'],
        ]);

        $entry = config("ai.providers.{$validated['provider']}");
        if (! is_array($entry) || ($entry['type'] ?? null) !== $validated['type']) {
            return response()->json(['ok' => false, 'message' => 'Unknown provider or type mismatch.'], 422);
        }

        $saved = null;
        if (filled($validated['id'] ?? null)) {
            $saved = AiConnection::where('user_id', Auth::id())->find((int) $validated['id']);
            if (! $saved) {
                return response()->json(['ok' => false, 'message' => 'Connection not found.'], 404);
            }
        }

        $config = null;
        if (filled($validated['config'] ?? null)) {
            $config = json_decode((string) $validated['config'], true);
            if (! is_array($config)) {
                return response()->json(['ok' => false, 'message' => 'Additional configuration must be valid JSON.'], 422);
            }
        }

        $connection = $saved ?? new AiConnection;
        $connection->user_id = Auth::id();
        $connection->is_active = true;
        $connection->type = $validated['type'];
        $connection->provider = $validated['provider'];
        $connection->model = filled($validated['model'] ?? null) ? $validated['model'] : $saved?->model;
        $connection->base_url = filled($validated['base_url'] ?? null) ? $validated['base_url'] : $saved?->base_url;
        $connection->config = $config ?? $saved?->config;

        // Typed key wins; blank falls back to the saved key (Edge TTS needs none).
        $connection->api_key = filled($validated['api_key'] ?? null)
            ? $validated['api_key']
            : $saved?->api_key;

        return response()->json(app(ConnectionTester::class)->test($connection));
    }
}
