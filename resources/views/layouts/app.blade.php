<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Dashboard' }} | OmniShorts OS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://db.onlinewebfonts.com/c/8cb707a9b8a73f8a7403336b861c3074?family=BubbledotICG-FinePos" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ time() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="5 3 19 12 5 21 5 3"></polygon>
                    </svg>
                </div>
                <span class="sidebar-brand">OmniShorts</span>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section-title">Content</div>
                
                <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="7" height="9" x="3" y="3" rx="1"></rect>
                        <rect width="7" height="5" x="14" y="3" rx="1"></rect>
                        <rect width="7" height="9" x="14" y="12" rx="1"></rect>
                        <rect width="7" height="5" x="3" y="16" rx="1"></rect>
                    </svg>
                    <span>Dashboard</span>
                </a>

                <a href="{{ route('videos.index') }}" class="nav-item {{ request()->routeIs('videos.index') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m22 8-6 4 6 4V8Z"></path>
                        <rect width="14" height="12" x="2" y="6" rx="2"></rect>
                    </svg>
                    <span>Content Library</span>
                </a>

                <a href="{{ route('videos.create') }}" class="nav-item {{ request()->routeIs('videos.create') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="17 8 12 3 7 8"></polyline>
                        <line x1="12" x2="12" y1="3" y2="15"></line>
                    </svg>
                    <span>Upload Reel</span>
                </a>

                <a href="{{ route('videos.bulk') }}" class="nav-item {{ request()->routeIs('videos.bulk*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"></path>
                        <path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"></path>
                        <path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"></path>
                    </svg>
                    <span>Bulk Upload</span>
                </a>

                <a href="{{ route('ai.videos.create') }}" class="nav-item {{ request()->routeIs('ai.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2a4 4 0 0 1 4 4c0 .6-.13 1.17-.36 1.68A4 4 0 0 1 20 11c0 .4-.05.78-.15 1.15A4 4 0 0 1 21 16a4 4 0 0 1-4 4c-.5 0-.98-.09-1.43-.25A4 4 0 0 1 12 22a4 4 0 0 1-3.57-2.25A4 4 0 0 1 7 20a4 4 0 0 1-4-4c0-.4.05-.78.15-1.15A4 4 0 0 1 3 11c0-.85.27-1.63.72-2.27A4 4 0 0 1 8 6c.6 0 1.17.13 1.68.36A4 4 0 0 1 12 2Z"></path>
                        <path d="M9 12h6"></path>
                        <path d="M12 9v6"></path>
                    </svg>
                    <span>AI Video</span>
                </a>

                <div class="nav-section-title">Publishing</div>

                <a href="{{ route('accounts.index') }}" class="nav-item {{ request()->routeIs('accounts.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <span>YouTube Channels</span>
                </a>

                <a href="{{ route('calendar.index') }}" class="nav-item {{ request()->routeIs('calendar.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect>
                        <line x1="16" x2="16" y1="2" y2="6"></line>
                        <line x1="8" x2="8" y1="2" y2="6"></line>
                        <line x1="3" x2="21" y1="10" y2="10"></line>
                    </svg>
                    <span>Calendar</span>
                </a>

                <div class="nav-section-title">System</div>

                <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="4" x2="4" y1="21" y2="14"></line>
                        <line x1="4" x2="4" y1="10" y2="3"></line>
                        <line x1="12" x2="12" y1="21" y2="12"></line>
                        <line x1="12" x2="12" y1="8" y2="3"></line>
                        <line x1="20" x2="20" y1="21" y2="16"></line>
                        <line x1="20" x2="20" y1="12" y2="3"></line>
                        <line x1="1" x2="7" y1="14" y2="14"></line>
                        <line x1="9" x2="15" y1="8" y2="8"></line>
                        <line x1="17" x2="23" y1="16" y2="16"></line>
                    </svg>
                    <span>Settings</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        @if(auth()->user()->avatar)
                            <img src="{{ auth()->user()->avatar }}" alt="{{ auth()->user()->name }}" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                        @else
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; color: #000;">
                                {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
                            </div>
                        @endif
                        <div>
                            <div style="font-size: 0.85rem; font-weight: 600;">{{ auth()->user()->name ?? 'User' }}</div>
                            <div style="font-size: 0.72rem; color: var(--text-dim);">PRO Creator</div>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" title="Logout" style="background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 4px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" x2="21" y1="12" y2="12"></line>
                                <line x1="9" x2="21" y1="12" y2="12"></line>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- Main Area -->
        <div class="main-content">
            <!-- Sticky Topbar -->
            <header class="topbar">
                <div class="topbar-left">
                    <button class="mobile-menu-toggle" id="mobileMenuBtn">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="3" x2="21" y1="6" y2="6"></line>
                            <line x1="3" x2="21" y1="12" y2="12"></line>
                            <line x1="3" x2="21" y1="18" y2="18"></line>
                        </svg>
                    </button>

                    @php
                        $activeChannel = auth()->user()->currentChannel();
                        $userChannels = auth()->user()->channels()->get();
                    @endphp

                    @if($activeChannel)
                    <div class="channel-switcher">
                        <div class="channel-pill" id="channelDropdownBtn">
                            <div class="channel-avatar">
                                @if($activeChannel->profile_image)
                                    <img src="{{ $activeChannel->profile_image }}" alt="">
                                @else
                                    {{ substr($activeChannel->name, 0, 1) }}
                                @endif
                            </div>
                            <div class="channel-info">
                                <span class="channel-name">{{ $activeChannel->name }}</span>
                                <span class="channel-tag">{{ $activeChannel->category ?? 'Short-form Content' }}</span>
                            </div>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-dim); margin-left: 4px;">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>

                        <div class="channel-dropdown-menu" id="channelDropdownMenu">
                            <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; padding: 6px 12px;">Switch Channel</div>
                            @foreach($userChannels as $ch)
                                <form method="POST" action="{{ route('channels.switch', $ch) }}" style="margin: 0;">
                                    @csrf
                                    <button type="submit" class="channel-option {{ $ch->id === $activeChannel->id ? 'active' : '' }}">
                                        <div class="channel-avatar" style="width: 24px; height: 24px; font-size: 0.75rem;">
                                            @if($ch->profile_image)
                                                <img src="{{ $ch->profile_image }}" alt="">
                                            @else
                                                {{ substr($ch->name, 0, 1) }}
                                            @endif
                                        </div>
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; font-size: 0.85rem;">{{ $ch->name }}</div>
                                            <div style="font-size: 0.72rem; color: var(--text-muted);">{{ $ch->videos()->count() }} reels · ⏰ {{ $ch->scheduleLabel() }}</div>
                                        </div>
                                        @if($ch->id === $activeChannel->id)
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent-emerald)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                        @endif
                                    </button>
                                </form>
                            @endforeach

                            <div style="border-top: 1px solid var(--border-subtle); margin: 6px 0; padding-top: 6px;">
                                <button type="button" class="channel-option" data-open-modal="googleOauthModal" data-close-menu style="color: var(--primary); font-weight: 600;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                                    <span>Google OAuth Settings</span>
                                </button>
                                <button type="button" class="channel-option" data-open-modal="newChannelModal" data-close-menu style="color: var(--primary); font-weight: 600;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="12" x2="12" y1="5" y2="19"></line>
                                        <line x1="5" x2="19" y1="12" y2="12"></line>
                                    </svg>
                                    <span>+ Add New Channel</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    @endif
                </div>

                <div class="topbar-right">
                    <a href="{{ route('videos.create') }}" class="btn btn-primary btn-sm">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" x2="12" y1="5" y2="19"></line>
                            <line x1="5" x2="19" y1="12" y2="12"></line>
                        </svg>
                        <span>Upload Reel</span>
                    </a>
                </div>
            </header>

            <!-- Main Page Content Slot -->
            <main class="page-container">
                @if(session('success'))
                    <div class="alert alert-success">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                            <span>{{ session('success') }}</span>
                        </div>
                        <button type="button" data-dismiss style="background: transparent; border: none; color: inherit; cursor: pointer;">✕</button>
                    </div>
                @endif

                @php
                    $oauthError = request()->query('oauth_error');
                @endphp
                @if(session('error'))
                    <div class="alert alert-error">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" x2="12" y1="8" y2="12"></line>
                                <line x1="12" x2="12.01" y1="16" y2="16"></line>
                            </svg>
                            <span>{{ session('error') }}</span>
                        </div>
                        <button type="button" data-dismiss style="background: transparent; border: none; color: inherit; cursor: pointer;">✕</button>
                    </div>
                @elseif($oauthError)
                    {{-- Set by the OAuth popup (popup-error.js) so the failure stays visible after the popup closes. --}}
                    <div class="alert alert-error">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" x2="12" y1="8" y2="12"></line>
                                <line x1="12" x2="12.01" y1="16" y2="16"></line>
                            </svg>
                            <span>{{ $oauthError }}</span>
                        </div>
                        <button type="button" data-dismiss style="background: transparent; border: none; color: inherit; cursor: pointer;">✕</button>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    <!-- Google OAuth Settings Modal (any channel, from the switcher) -->
    @if($activeChannel)
    <div class="modal-backdrop" id="googleOauthModal">
        <div class="modal-dialog">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Google OAuth Settings</h3>
                    <p class="card-subtitle">Client ID + Secret per channel — stored encrypted, never shown again</p>
                </div>
                <button type="button" data-close-modal="googleOauthModal" style="background: transparent; border: none; color: var(--text-dim); cursor: pointer; font-size: 1.2rem;">✕</button>
            </div>
            <form method="POST" action="{{ route('accounts.google.config') }}">
                @csrf
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="oauth_channel_id">Channel</label>
                        <select name="channel_id" id="oauth_channel_id" class="form-select">
                            @foreach($userChannels as $ch)
                                <option value="{{ $ch->id }}"
                                    data-client-id="{{ $ch->google_client_id ?? '' }}"
                                    data-has-secret="{{ $ch->hasGoogleClientSecret() ? '1' : '0' }}"
                                    {{ $ch->id === $activeChannel->id ? 'selected' : '' }}>
                                    {{ $ch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="oauth_client_id">OAuth Client ID</label>
                        <input type="text" id="oauth_client_id" name="google_client_id" class="form-input" placeholder="1234567890-abc.apps.googleusercontent.com" value="{{ $activeChannel->google_client_id }}">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="oauth_client_secret">Client Secret</label>
                        <input type="password" id="oauth_client_secret" name="google_client_secret" class="form-input" placeholder="{{ $activeChannel->hasGoogleClientSecret() ? '••• saved •••' : 'GOCSPX-…' }}" autocomplete="new-password">
                        <label id="oauth_clear_wrap" style="display: {{ $activeChannel->hasGoogleClientSecret() ? 'inline-flex' : 'none' }}; align-items: center; gap: 6px; margin-top: 8px; font-size: 0.78rem; color: var(--text-dim); cursor: pointer;">
                            <input type="checkbox" name="clear_secret" value="1" id="oauth_clear_secret" style="accent-color: var(--primary);">
                            Remove saved secret
                        </label>
                    </div>
                    <div id="oauth_status" style="font-size: 0.8rem; color: var(--text-dim);">
                        @php
                            $activeCreds = $activeChannel->googleOAuthCredentials();
                        @endphp
                        @if($activeCreds['source'] === 'channel')
                            This channel uses its own Client ID <span style="color: var(--accent-emerald);">(set below)</span>.
                        @elseif($activeCreds['client_id'])
                            Using app-level Client ID from <code style="font-size: 0.75rem;">.env</code> — set one below to override.
                        @else
                            Not configured yet. Paste your Client ID + Secret from <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener" style="color: var(--primary); text-decoration: underline;">Google Cloud Console</a>.
                        @endif
                    </div>
                </div>
                <div style="padding: 16px 24px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn btn-secondary" data-close-modal="googleOauthModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Credentials</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- Create Channel Modal -->
    <div class="modal-backdrop" id="newChannelModal">
        <div class="modal-dialog">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Create New Channel</h3>
                    <p class="card-subtitle">Manage a separate brand, niche, or media entity</p>
                </div>
                <button type="button" data-close-modal="newChannelModal" style="background: transparent; border: none; color: var(--text-dim); cursor: pointer; font-size: 1.2rem;">✕</button>
            </div>
            <form method="POST" action="{{ route('channels.store') }}">
                @csrf
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Channel Name</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g. AI Blueprint, Fitness Daily" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Handle</label>
                        <input type="text" name="handle" class="form-input" placeholder="@yourhandle">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Primary Category</label>
                        <select name="category" class="form-select">
                            <option value="Tech & AI">Tech & AI</option>
                            <option value="Health & Fitness">Health & Fitness</option>
                            <option value="Business & Finance">Business & Finance</option>
                            <option value="Entertainment & Gaming">Entertainment & Gaming</option>
                            <option value="Education & How-To">Education & How-To</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Short Description</label>
                        <textarea name="description" class="form-textarea" placeholder="What is this channel about?"></textarea>
                    </div>
                </div>
                <div style="padding: 16px 24px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn btn-secondary" data-close-modal="newChannelModal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Channel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts (external only — strict CSP has no unsafe-inline) -->
    <script src="{{ asset('js/app.js') }}"></script>
    @stack('scripts')
</body>
</html>
