@extends('layouts.app', ['title' => 'Settings'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Settings</h1>
        <p>Configure the AI engine that generates hooks, titles, and descriptions for scheduled uploads.</p>
    </div>
</div>

<div style="max-width: 720px;">
    <div class="card" style="margin-bottom: 22px;">
        <div class="card-body" style="padding: 20px 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--accent-cyan); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a4 4 0 0 1 4 4c0 .6-.13 1.17-.36 1.68A4 4 0 0 1 20 11c0 .4-.05.78-.15 1.15A4 4 0 0 1 21 16a4 4 0 0 1-4 4c-.5 0-.98-.09-1.43-.25A4 4 0 0 1 12 22a4 4 0 0 1-3.57-2.25A4 4 0 0 1 7 20a4 4 0 0 1-4-4c0-.4.05-.78.15-1.15A4 4 0 0 1 3 11c0-.85.27-1.63.72-2.27A4 4 0 0 1 8 6c.6 0 1.17.13 1.68.36A4 4 0 0 1 12 2Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 1rem;">Gemini AI</div>
                    <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                        Video analysis for scheduled uploads — generates hook, title, description, hashtags, thumbnail text, and more.
                    </div>
                </div>
                <span class="badge" style="{{ $geminiEnabled ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);' }}">
                    {{ $geminiEnabled ? '● Enabled' : '○ Disabled' }}
                </span>
            </div>

            <form method="POST" action="{{ route('settings.gemini.save') }}">
                @csrf

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="enabled" value="1" style="accent-color: var(--primary);" {{ $geminiEnabled ? 'checked' : '' }}>
                        Enable Gemini AI Analysis
                    </label>
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                        When ON, the cron analyzes each video with Gemini right before its scheduled YouTube upload. When OFF, uploads use the existing metadata.
                    </span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="gemini_model">Gemini Model</label>
                    <input type="text" name="model" id="gemini_model" class="form-input" value="{{ $geminiModel }}" placeholder="e.g. gemini-1.5-flash" list="geminiModelsList" style="max-width: 360px;" required>
                    <datalist id="geminiModelsList">
                        @foreach($geminiModels as $model)
                            <option value="{{ $model }}"></option>
                        @endforeach
                    </datalist>
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                        Type any Gemini model name, e.g. <code style="font-size: 0.72rem;">gemini-1.5-flash</code>, <code style="font-size: 0.72rem;">gemini-2.5-flash</code>, or a preview model.
                    </span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="gemini_api_key">Gemini API Key</label>
                    <input type="password" id="gemini_api_key" name="api_key" class="form-input" placeholder="{{ $geminiHasApiKey ? '••• saved •••' : 'AIza…' }}" autocomplete="new-password" style="max-width: 420px;">
                    @if($geminiHasApiKey)
                        <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 0.78rem; color: var(--text-dim); cursor: pointer;">
                            <input type="checkbox" name="remove_api_key" value="1" style="accent-color: var(--primary);">
                            Remove saved API key
                        </label>
                    @endif
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                        Stored encrypted server-side. Never shown again — leave blank to keep the saved one.
                    </span>
                </div>

                <div style="margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border-subtle);">
                    <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 2px;">Per-Channel Overrides</div>
                    <div style="font-size: 0.78rem; color: var(--text-dim); margin-bottom: 12px;">
                        One channel can use Gemini while another skips it. Channels set to <strong>Default</strong> follow the global toggle above.
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        @forelse($channels as $channel)
                            <div class="channel-override-row">
                                <div style="min-width: 0;">
                                    <div style="font-weight: 600; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $channel->name }}</div>
                                    <div style="font-size: 0.72rem; color: var(--text-dim);">
                                        @if($channel->gemini_enabled === null)
                                            Currently: global ({{ $geminiEnabled ? 'Enabled' : 'Disabled' }})
                                        @else
                                            Currently: {{ $channel->gemini_enabled ? 'Enabled' : 'Disabled' }}
                                        @endif
                                    </div>
                                </div>
                                <select name="channel_overrides[{{ $channel->id }}]" class="form-input" style="width: auto; max-width: 160px; padding: 6px 10px; font-size: 0.8rem;">
                                    <option value="default" {{ $channel->gemini_enabled === null ? 'selected' : '' }}>Default (global)</option>
                                    <option value="enabled" {{ $channel->gemini_enabled === true ? 'selected' : '' }}>Enabled</option>
                                    <option value="disabled" {{ $channel->gemini_enabled === false ? 'selected' : '' }}>Disabled</option>
                                </select>
                            </div>
                        @empty
                            <div style="font-size: 0.8rem; color: var(--text-muted);">No channels yet — create one from the channel switcher first.</div>
                        @endforelse
                    </div>
                </div>

                <div class="save-row" style="margin-top: 16px;">
                    <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="testGeminiBtn" data-test-url="{{ route('settings.gemini.test') }}" @if(!$geminiHasApiKey) disabled title="Save an API key first" @endif>Test Gemini Connection</button>
                    <span id="geminiTestResult" style="font-size: 0.8rem;"></span>
                </div>
            </form>
        </div>
    </div>

    <!-- Scheduler & Cron Jobs -->
    <div class="card" style="margin-bottom: 22px;">
        <div class="card-body" style="padding: 20px 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--accent-emerald); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 1rem;">Scheduler &amp; Cron Jobs</div>
                    <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                        Auto-publish reels, refresh analytics, and prune old files — on your schedule.
                    </div>
                </div>
                <span class="badge" style="{{ $cronEnabled ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);' }}">
                    {{ $cronEnabled ? '● Enabled' : '○ Disabled' }}
                </span>
            </div>

            <form method="POST" action="{{ route('settings.cron.save') }}">
                @csrf

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="enabled" value="1" style="accent-color: var(--primary);" {{ $cronEnabled ? 'checked' : '' }}>
                        Enable scheduler (master switch)
                    </label>
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                        When OFF, no job runs automatically — manual buttons (Run Now, Refresh All) still work.
                    </span>
                </div>

                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                    <div class="cron-job-row">
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 0.85rem;">Auto-publish due reels</div>
                            <div style="font-size: 0.72rem; color: var(--text-dim);">Checks every minute · uploads scheduled videos to YouTube</div>
                            <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 3px;">
                                Last ran: @if(isset($cronLastRuns['publish']))<strong style="color: var(--text-main);">{{ $cronLastRuns['publish']->diffForHumans() }}</strong> · {{ $cronLastRuns['publish']->format('h:i A') }}@else<em>never</em>@endif
                            </div>
                        </div>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer; flex-shrink: 0;">
                            <input type="checkbox" name="publish_enabled" value="1" style="accent-color: var(--primary);" {{ $cronPublishEnabled ? 'checked' : '' }}>
                            On
                        </label>
                    </div>
                    <div class="cron-job-row">
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 0.85rem;">Refresh YouTube analytics</div>
                            <div style="font-size: 0.72rem; color: var(--text-dim);">Runs twice daily (08:00 &amp; 20:00) · views, likes, comments, shares, subscribers</div>
                            <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 3px;">
                                Last ran: @if(isset($cronLastRuns['analytics']))<strong style="color: var(--text-main);">{{ $cronLastRuns['analytics']->diffForHumans() }}</strong> · {{ $cronLastRuns['analytics']->format('h:i A') }}@else<em>never</em>@endif
                            </div>
                        </div>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer; flex-shrink: 0;">
                            <input type="checkbox" name="analytics_enabled" value="1" style="accent-color: var(--primary);" {{ $cronAnalyticsEnabled ? 'checked' : '' }}>
                            On
                        </label>
                    </div>
                    <div class="cron-job-row">
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; font-size: 0.85rem;">Prune old video files</div>
                            <div style="font-size: 0.72rem; color: var(--text-dim);">Runs daily · frees hosting storage by deleting files past retention</div>
                            <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 3px;">
                                Last ran: @if(isset($cronLastRuns['prune']))<strong style="color: var(--text-main);">{{ $cronLastRuns['prune']->diffForHumans() }}</strong> · {{ $cronLastRuns['prune']->format('h:i A') }}@else<em>never</em>@endif
                            </div>
                        </div>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer; flex-shrink: 0;">
                            <input type="checkbox" name="prune_enabled" value="1" style="accent-color: var(--primary);" {{ $cronPruneEnabled ? 'checked' : '' }}>
                            On
                        </label>
                    </div>
                </div>

                <div class="save-row">
                    <button type="submit" class="btn btn-primary btn-sm">Save Cron Settings</button>
                    @if($lastCronCheckAt)
                        <span style="font-size: 0.76rem; color: var(--text-dim);">Last scheduler check: {{ $lastCronCheckAt->diffForHumans() }} · {{ $lastCronCheckAt->format('h:i A') }}</span>
                    @endif
                </div>
            </form>

            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-subtle);">
                <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 2px;">Server Cron (OS-level)</div>
                <div style="font-size: 0.78rem; color: var(--text-dim); margin-bottom: 12px;">
                    The scheduler needs ONE OS entry that runs <code style="font-size: 0.72rem;">artisan schedule:run</code> every minute — the job list above is read from the app automatically, so nothing else ever needs updating. On Windows the button creates a Task Scheduler entry; on Linux/Hostinger it installs a crontab line.
                </div>
                <div class="save-row" style="margin-bottom: 14px;">
                    <form method="POST" action="{{ route('settings.cron.install') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">↻ Install / Sync Cron</button>
                    </form>
                    <form method="POST" action="{{ route('settings.cron.uninstall') }}">
                        @csrf
                        <button type="submit" class="btn btn-danger btn-sm" data-confirm="Remove the OS-level cron entry? Scheduled uploads will stop until reinstalled.">Uninstall Cron</button>
                    </form>
                </div>
                <div style="font-size: 0.78rem; color: var(--text-dim); margin-bottom: 4px;">
                    Manual line for cPanel / Hostinger Cron Jobs (Linux only):
                </div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <code style="font-size: 0.72rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); padding: 6px 10px; border-radius: 6px; display: inline-block; max-width: 100%; overflow-x: auto;">{{ $cronLine }}</code>
                    <button type="button" class="btn btn-secondary btn-sm" data-copy="{{ $cronLine }}">Copy</button>
                </div>
            </div>
        </div>
    </div>

    @include('settings.partials.ai-connections')
    @include('settings.partials.content-type-ai')
    @include('settings.partials.daily-auto-generation')
</div>

    <!-- Deployment & Setup -->
    <div class="card" style="margin-bottom: 22px;">
        <div class="card-body" style="padding: 20px 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--accent-cyan); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"></path><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"></path></svg>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 1rem;">Deployment &amp; Setup</div>
                    <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                        One-click post-deploy setup — generates a missing encryption key, creates database tables, links storage, and caches config/routes/views.
                    </div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px;">
                <div class="cron-job-row">
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.85rem;">Encryption key (APP_KEY)</div>
                        <div style="font-size: 0.72rem; color: var(--text-dim);">php artisan key:generate — only runs if no key is set (never overwrites an existing one)</div>
                    </div>
                    <span class="badge" style="{{ $deploy['has_key'] ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);' }}">{{ $deploy['has_key'] ? '✔ Set' : '✗ Missing' }}</span>
                </div>
                <div class="cron-job-row">
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.85rem;">Database tables</div>
                        <div style="font-size: 0.72rem; color: var(--text-dim);">php artisan migrate --force — idempotent, safe to re-run</div>
                    </div>
                    @if($deploy['migrations'] === null)
                        <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);">? DB unreachable</span>
                    @else
                        <span class="badge" style="{{ $deploy['migrations'] ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);' }}">{{ $deploy['migrations'] ? '✔ Ready' : '✗ Missing' }}</span>
                    @endif
                </div>
                <div class="cron-job-row">
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.85rem;">Storage link</div>
                        <div style="font-size: 0.72rem; color: var(--text-dim);">php artisan storage:link — makes uploaded files publicly visible</div>
                    </div>
                    <span class="badge" style="{{ $deploy['has_storage_link'] ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3);' }}">{{ $deploy['has_storage_link'] ? '✔ Linked' : '✗ Missing' }}</span>
                </div>
                <div class="cron-job-row">
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 0.85rem;">Config / route / view cache</div>
                        <div style="font-size: 0.72rem; color: var(--text-dim);">php artisan optimize — caches routes, config, and views for speed</div>
                    </div>
                    <span class="badge" style="{{ $deploy['config_cached'] ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);' }}">{{ $deploy['config_cached'] ? '✔ Cached' : '○ Not cached' }}</span>
                </div>
            </div>

            <div class="save-row">
                <form method="POST" action="{{ route('settings.deploy.run') }}" data-confirm="Run deployment setup now? This creates database tables and caches config/routes/views.">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">⚡ Run Deployment Setup</button>
                </form>
                <span style="font-size: 0.76rem; color: var(--text-dim);">Each step is idempotent — re-running is safe.</span>
            </div>

            @if(session('deploy_results'))
                <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border-subtle); display: flex; flex-direction: column; gap: 7px;">
                    @foreach(session('deploy_results') as $r)
                        <div class="cron-job-row">
                            <div style="flex: 1; min-width: 0; display: flex; align-items: baseline; gap: 8px;">
                                <span style="color: {{ $r['status'] === 'done' ? '#34d399' : ($r['status'] === 'failed' ? '#f87171' : 'var(--text-dim)') }}; flex-shrink: 0;">{{ $r['status'] === 'done' ? '✔' : ($r['status'] === 'failed' ? '✗' : '–') }}</span>
                                <strong style="font-size: 0.8rem; flex-shrink: 0;">{{ $r['name'] }}</strong>
                                <span style="font-size: 0.76rem; color: var(--text-dim); overflow: hidden; text-overflow: ellipsis;">{{ $r['detail'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/settings.js') }}"></script>
<script src="{{ asset('js/ai.js') }}?v={{ time() }}"></script>
@endpush
