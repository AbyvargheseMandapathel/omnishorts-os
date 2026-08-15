@extends('layouts.app', ['title' => 'YouTube Channels'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>YouTube Channels</h1>
        <p>Connect your YouTube channels with Google. Scheduled reels go live there automatically.</p>
    </div>
    <div class="page-actions" style="flex-wrap: wrap; gap: 10px;">
        <button type="button" class="btn btn-google" id="connectPopupBtn" data-oauth-url="{{ route('accounts.youtube.connect', ['popup' => 1]) }}">
            <svg width="19" height="19" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
            <span>Continue with Google</span>
        </button>
        <a href="{{ route('accounts.youtube.connect') }}" class="btn btn-secondary" title="Open Google sign-in in the full page instead of a popup">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v3m6.366-.366-2.12 2.12M21 12h-3m.366 6.366-2.12-2.12M12 21v-3m-6.366.366 2.12-2.12M3 12h3m-.366-6.366 2.12 2.12"></path></svg>
            <span>Full page</span>
        </a>
    </div>
</div>

@php
    $creds = $channel->googleOAuthCredentials();
    $hasChannelCreds = $creds['source'] === 'channel';
    $hasSecret = $channel->hasGoogleClientSecret();
@endphp

<div class="card" style="margin-bottom: 22px;">
    <div class="card-body" style="padding: 20px 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; font-size: 1rem;">Google OAuth</div>
                <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                    @if($hasChannelCreds)
                        This channel uses its own Client ID <span style="color: var(--accent-emerald);">(set below)</span>.
                    @elseif($creds['client_id'])
                        Using app-level Client ID from <code style="font-size: 0.75rem;">.env</code> — set one below to override.
                    @else
                        No Google OAuth configured yet. Paste your Client ID + Secret from the <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" style="color: var(--primary); text-decoration: underline;">Google Cloud Console</a>.
                    @endif
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('accounts.google.config') }}">
            @csrf
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-bottom: 14px;">
                <div>
                    <label class="form-label" for="google_client_id">OAuth Client ID</label>
                    <input type="text" id="google_client_id" name="google_client_id" class="form-input" placeholder="1234567890-abc.apps.googleusercontent.com" value="{{ $channel->google_client_id }}">
                </div>
                <div>
                    <label class="form-label" for="google_client_secret">Client Secret</label>
                    <input type="password" id="google_client_secret" name="google_client_secret" class="form-input" placeholder="{{ $hasSecret ? '••• saved •••' : 'GOCSPX-…' }}" autocomplete="new-password">
                    @if($hasSecret)
                        <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 0.78rem; color: var(--text-dim); cursor: pointer;">
                            <input type="checkbox" name="clear_secret" value="1" style="accent-color: var(--primary);">
                            Remove saved secret
                        </label>
                    @endif
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button type="submit" class="btn btn-primary btn-sm">Save Credentials</button>
                <span style="font-size: 0.78rem; color: var(--text-dim);">
                    @if($hasSecret)
                        Secret is stored encrypted — it's never shown again. Leave it blank to keep the saved one.
                    @else
                        Leave blank to keep using the app-level .env config.
                    @endif
                </span>
            </div>
            @error('google_client_id')
                <span style="display: block; margin-top: 10px; font-size: 0.8rem; color: var(--danger, #f87171);">{{ $message }}</span>
            @enderror
        </form>
    </div>
</div>

@if($errors->has('google'))
    <div class="alert alert-error" style="margin-bottom: 22px;">
        <span>{{ $errors->first('google') }}</span>
    </div>
@endif

@if($accounts->isEmpty())
    <div class="card" style="text-align: center; padding: 64px 24px;">
        <div style="width: 64px; height: 64px; border-radius: 16px; background: rgba(239, 68, 68, 0.12); color: #ef4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"></path><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"></polygon></svg>
        </div>
        <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 8px;">No YouTube channel connected</h3>
        <p style="color: var(--text-muted); font-size: 0.92rem; max-width: 440px; margin: 0 auto 24px;">
            Sign in with Google, pick one of your YouTube channels, grant permission, and your scheduled reels will publish there automatically.
        </p>
        <button type="button" class="btn btn-google" data-oauth-url="{{ route('accounts.youtube.connect', ['popup' => 1]) }}">
            <svg width="19" height="19" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
            <span>Continue with Google</span>
        </button>
    </div>
@else
    <div style="display: flex; flex-direction: column; gap: 16px; max-width: 720px;">
        @foreach($accounts as $account)
            @php
                $accountCreds = $account->googleOAuthCredentials();
                $accountHasOwn = $accountCreds['source'] === 'account';
            @endphp
            <div class="card" style="overflow: hidden;">
                <div class="card-body" style="padding: 20px 24px; display: flex; align-items: center; gap: 16px;">
                    @if($account->avatar)
                        <img src="{{ $account->avatar }}" alt="" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                    @else
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: #ef4444; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem; flex-shrink: 0;">▶</div>
                    @endif
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <span style="font-weight: 700; font-size: 1rem;">{{ $account->account_name }}</span>
                            @if($account->status === 'connected')
                                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">● Connected</span>
                            @else
                                <span class="badge" style="background: rgba(248, 113, 113, 0.12); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.3);">⚠ Expired — Reconnect</span>
                            @endif
                            @if($accountHasOwn)
                                <span class="badge" style="background: rgba(255,255,255,0.06); color: #c4c2c3; border: 1px solid rgba(255,255,255,0.14);">Own OAuth</span>
                            @endif
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                            {{ $account->handle ?? 'YouTube Channel' }}
                            <span style="margin: 0 6px;">•</span>
                            {{ number_format($account->follower_count) }} subscribers
                            <span style="margin: 0 6px;">•</span>
                            <span style="color: var(--accent-cyan);">⏰ {{ $account->scheduleLabel() }}</span>
                            @if(isset($account->credentials['youtube_channel_id']))
                                <span style="margin: 0 6px;">•</span>
                                <span style="color: var(--accent-emerald);">OAuth connected</span>
                            @endif
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; flex-shrink: 0;">
                        <button type="button" class="btn btn-secondary btn-sm" data-toggle-panel="#cron-{{ $account->id }}" title="Set this channel's own posting cron">Cron</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-toggle-panel="#oauth-{{ $account->id }}">OAuth</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-reconnect-url="{{ route('accounts.youtube.connect', ['account_id' => $account->id, 'popup' => 1]) }}" title="Reconnect this channel with its own Google OAuth">Reconnect</button>
                        <form method="POST" action="{{ route('accounts.disconnect', $account) }}" data-confirm="Disconnect this YouTube channel?">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Disconnect</button>
                        </form>
                    </div>
                </div>

                <!-- Per-account posting cron -->
                <div class="account-oauth-panel" id="cron-{{ $account->id }}">
                    <div style="font-size: 0.78rem; color: var(--text-dim); margin-bottom: 12px;">
                        @if($account->hasOwnSchedule())
                            Own cron: <strong style="color: var(--text-main);">{{ $account->scheduleLabel() }}</strong> <span style="color: var(--accent-emerald);">(set below)</span>.
                        @else
                            Using channel default <strong style="color: var(--text-main);">{{ $account->scheduleLabel() }}</strong> — set your own below to override.
                        @endif
                    </div>
                    <form method="POST" action="{{ route('accounts.schedule.update', $account) }}">
                        @csrf
                        @method('PUT')
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: end;">
                            <div>
                                <label class="form-label">Posts Per Day</label>
                                <select name="posts_per_day" class="form-select cron-posts-per-day" data-target="#cron-times-{{ $account->id }}">
                                    @for($i = 1; $i <= 10; $i++)
                                        <option value="{{ $i }}" {{ $account->postsPerDay() == $i ? 'selected' : '' }}>{{ $i }} post{{ $i > 1 ? 's' : '' }} / day</option>
                                    @endfor
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Post Times</label>
                                <div class="cron-times" id="cron-times-{{ $account->id }}" style="display: flex; flex-direction: column; gap: 8px;">
                                    @foreach($account->postingTimes() as $index => $time)
                                        <div class="post-time-row" style="display: flex; align-items: center; gap: 8px;">
                                            <span style="width: 24px; height: 24px; border-radius: 50%; background: rgba(255,255,255,0.07); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.72rem; flex-shrink: 0;">#{{ $index + 1 }}</span>
                                            <input type="time" name="post_times[]" class="form-input" value="{{ $time }}" style="max-width: 180px;">
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <button type="submit" class="btn btn-primary btn-sm">Save Cron</button>
                                <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.76rem; color: var(--text-dim); cursor: pointer;">
                                    <input type="checkbox" name="use_channel_default" value="1" style="accent-color: var(--primary);">
                                    Use channel default
                                </label>
                            </div>
                        </div>
                        <div style="font-size: 0.76rem; color: var(--text-dim); margin-top: 10px;">Bulk uploads queue into these slots; each YouTube channel keeps its own schedule and calendar.</div>
                    </form>
                </div>

                <!-- Per-account OAuth config -->
                <div class="account-oauth-panel" id="oauth-{{ $account->id }}">
                    <div style="font-size: 0.78rem; color: var(--text-dim); margin-bottom: 12px;">
                        @if($accountHasOwn)
                            This channel uses its own Client ID <span style="color: var(--accent-emerald);">(set below)</span>.
                        @elseif($accountCreds['client_id'])
                            Using {{ $accountCreds['source'] === 'channel' ? 'channel' : 'app (.env)' }} Client ID — set one below to override.
                        @else
                            No OAuth config for this channel yet. Paste its Client ID + Secret, or use <strong>Reconnect</strong>.
                        @endif
                    </div>
                    <form method="POST" action="{{ route('accounts.google.config') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; align-items: end;">
                        @csrf
                        <input type="hidden" name="account_id" value="{{ $account->id }}">
                        <div>
                            <label class="form-label">OAuth Client ID</label>
                            <input type="text" name="google_client_id" class="form-input" placeholder="1234567890-abc.apps.googleusercontent.com" value="{{ $account->google_client_id }}">
                        </div>
                        <div>
                            <label class="form-label">Client Secret</label>
                            <input type="password" name="google_client_secret" class="form-input" placeholder="{{ $account->hasGoogleClientSecret() ? '••• saved •••' : 'GOCSPX-…' }}" autocomplete="new-password">
                            @if($account->hasGoogleClientSecret())
                                <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 7px; font-size: 0.76rem; color: var(--text-dim); cursor: pointer;">
                                    <input type="checkbox" name="clear_secret" value="1" style="accent-color: var(--primary);">
                                    Remove saved secret
                                </label>
                            @endif
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
