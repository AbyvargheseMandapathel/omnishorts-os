@extends('layouts.app', ['title' => 'Dashboard'])

@section('content')
@if($reconnectAccounts->isNotEmpty())
    @foreach($reconnectAccounts as $expiredAccount)
        <div class="alert alert-error" style="margin-bottom: 18px;">
            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" x2="12" y1="9" y2="13"></line><line x1="12" x2="12.01" y1="17" y2="17"></line></svg>
                <span style="flex: 1; min-width: 200px;">Your YouTube connection for <strong>{{ $expiredAccount->account_name }}</strong> expired — re-connect with one click to resume auto-publishing.</span>
                <button type="button" class="btn btn-google btn-sm" data-reconnect-url="{{ route('accounts.youtube.connect', ['account_id' => $expiredAccount->id, 'popup' => 1]) }}">
                    <svg width="15" height="15" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
                    <span>Reconnect</span>
                </button>
            </div>
        </div>
    @endforeach
@endif

<div class="page-header">
    <div class="page-title-group">
        <h1>{{ $channel->name }}</h1>
        <p>Upload edited reels, schedule them on the calendar, and let the scheduler post them to YouTube automatically.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('videos.bulk') }}" class="btn btn-secondary">
            <span>📦 Import Bundle</span>
        </a>
        <a href="{{ route('videos.create') }}" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
            <span>Upload Reel</span>
        </a>
    </div>
</div>

<!-- KPI Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div>
            <div class="stat-label">Reels in Library</div>
            <div class="stat-value">{{ $totalVideos }}</div>
            <div class="stat-delta">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"></polyline></svg>
                <span>{{ $readyVideos }} ready to schedule</span>
            </div>
        </div>
        <div class="stat-icon-wrapper" style="color: var(--primary);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 8-6 4 6 4V8Z"></path><rect width="14" height="12" x="2" y="6" rx="2"></rect></svg>
        </div>
    </div>

    <div class="stat-card cyan">
        <div>
            <div class="stat-label">Scheduled for YouTube</div>
            <div class="stat-value">{{ $scheduledCount }}</div>
            <div class="stat-delta" style="color: var(--accent-cyan);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                <span>Auto-publishing daily</span>
            </div>
        </div>
        <div class="stat-icon-wrapper" style="color: var(--accent-cyan);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect><line x1="16" x2="16" y1="2" y2="6"></line><line x1="8" x2="8" y1="2" y2="6"></line><line x1="3" x2="21" y1="10" y2="10"></line></svg>
        </div>
    </div>

    <div class="stat-card emerald">
        <div>
            <div class="stat-label">Published on YouTube</div>
            <div class="stat-value">{{ $publishedCount }}</div>
            <div class="stat-delta" style="color: var(--accent-emerald);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"></polyline></svg>
                <span>Live now</span>
            </div>
        </div>
        <div class="stat-icon-wrapper" style="color: var(--accent-emerald);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Total Views</div>
            <div class="stat-value">{{ $totalViews ? number_format($totalViews) : '—' }}</div>
            <div class="stat-delta">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="18 15 12 9 6 15"></polyline></svg>
                <span>Real stats across {{ $publishedCount }} published reel{{ $publishedCount === 1 ? '' : 's' }}</span>
            </div>
        </div>
        <div class="stat-icon-wrapper" style="color: var(--primary);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
        </div>
    </div>

    <div class="stat-card secondary">
        <div>
            <div class="stat-label">Total Engagement</div>
            <div class="stat-value">{{ $totalLikes + $totalComments + $totalShares ? number_format($totalLikes + $totalComments + $totalShares) : '—' }}</div>
            <div class="stat-delta" style="color: var(--secondary);">
                <span>{{ number_format($totalLikes) }} likes · {{ number_format($totalComments) }} comments · {{ number_format($totalShares) }} shares</span>
            </div>
        </div>
        <div class="stat-icon-wrapper" style="color: var(--accent-rose);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
        </div>
    </div>

    <div class="stat-card secondary">
        <div>
            <div class="stat-label">YouTube Subscribers</div>
            <div class="stat-value">{{ $totalSubscribers ? number_format($totalSubscribers) : '—' }}</div>
            <div class="stat-delta" style="color: var(--secondary);">
                <span>Refreshed twice daily from YouTube</span>
            </div>
        </div>
        <div class="stat-icon-wrapper" style="color: var(--accent-rose);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"></path><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"></polygon></svg>
        </div>
    </div>
</div>

<!-- Main 2-Column Content Area -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 28px; align-items: start;">
    <!-- Left Column: Content Pipeline -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Recent Reels</h3>
                <p class="card-subtitle">Your latest uploads and their scheduling state</p>
            </div>
            <a href="{{ route('videos.index') }}" style="font-size: 0.85rem; font-weight: 600; color: var(--primary);">View All ({{ $totalVideos }}) →</a>
        </div>
        <div class="card-body" style="padding: 0;">
            @if($recentVideos->isEmpty())
                <div style="text-align: center; padding: 48px 24px;">
                    <div style="width: 48px; height: 48px; border-radius: 14px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 8-6 4 6 4V8Z"></path><rect width="14" height="12" x="2" y="6" rx="2"></rect></svg>
                    </div>
                    <h4 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 6px;">No reels yet</h4>
                    <p style="color: var(--text-muted); font-size: 0.88rem; max-width: 360px; margin: 0 auto 20px;">Upload your edited reels or import a whole bundle to auto-queue them.</p>
                    <a href="{{ route('videos.bulk') }}" class="btn btn-primary btn-sm">Import Bundle Pack</a>
                </div>
            @else
                <div style="display: flex; flex-direction: column;">
                    @foreach($recentVideos as $video)
                        <div class="hover-row" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid var(--border-subtle); gap: 16px;">
                            <div style="display: flex; align-items: center; gap: 16px; min-width: 0;">
                                <div style="width: 44px; height: 64px; border-radius: 8px; background: linear-gradient(180deg, #141417, #0b0b0d); border: 1px solid var(--border-subtle); display: flex; flex-direction: column; align-items: center; justify-content: center; flex-shrink: 0; position: relative;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="white" opacity="0.8"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                    <span style="position: absolute; bottom: 2px; font-size: 0.65rem; color: #cbd5e1; font-weight: 700;">@if($video->duration){{ $video->duration }}s@else—@endif</span>
                                </div>
                                <div style="min-width: 0;">
                                    <a href="{{ route('videos.show', $video) }}" style="font-weight: 600; font-size: 0.95rem; color: var(--text-main); display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        {{ $video->title }}
                                    </a>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap;">
                                        @if($video->status === 'draft')
                                            <span class="badge badge-draft">Draft</span>
                                        @elseif($video->status === 'processing')
                                            <span class="badge badge-processing">Processing</span>
                                        @elseif($video->status === 'ready')
                                            <span class="badge badge-ready">Ready</span>
                                        @elseif($video->status === 'scheduled')
                                            <span class="badge badge-scheduled">Scheduled</span>
                                        @elseif($video->status === 'published')
                                            <span class="badge badge-published">Published</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <a href="{{ route('videos.show', $video) }}" class="btn btn-secondary btn-sm">
                                Review & Post
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <!-- Performance: real YouTube stats -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3 class="card-title">Performance</h3>
                <p class="card-subtitle">Real YouTube stats · refreshed twice daily</p>
            </div>
            <form method="POST" action="{{ route('dashboard.analytics.refresh') }}">
                @csrf
                <button type="submit" class="btn btn-secondary btn-sm">↻ Refresh All</button>
            </form>
        </div>
        <div class="card-body">
            @php
                $curve = $viewsCurve;
                $maxVal = max($curve ?: [1]) ?: 1;
                $count = count($curve);
                $pts = [];
                foreach ($curve as $i => $v) {
                    $x = $count <= 1 ? 50 : round($i * (100 / max(1, $count - 1)), 2);
                    $y = round(26 - ($v / $maxVal) * 22, 2);
                    $pts[] = $x.','.$y;
                }
            @endphp
            <div style="margin-bottom: 18px;">
                @if($count > 1)
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px;">
                        <span style="font-size: 0.78rem; font-weight: 700; color: var(--text-dim);">Views — last {{ $count }} day{{ $count === 1 ? '' : 's' }}</span>
                        <span style="font-size: 0.78rem; font-weight: 700; color: var(--primary);">{{ number_format(max($curve)) }} peak</span>
                    </div>
                    <svg viewBox="0 0 100 30" preserveAspectRatio="none" style="width: 100%; height: 64px; display: block;">
                        <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                    </svg>
                @else
                    <div style="text-align: center; padding: 28px 12px; border: 1px dashed var(--border-subtle); border-radius: var(--radius-md); color: var(--text-muted); font-size: 0.82rem;">
                        Growth curve appears once reels have real stats (first refresh after publish).
                    </div>
                @endif
            </div>

            @if($bestPerformers->isNotEmpty())
                <div style="display: flex; flex-direction: column;">
                    @foreach($bestPerformers as $i => $pub)
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--border-subtle);">
                            <div style="min-width: 0; display: flex; align-items: center; gap: 10px;">
                                <span style="font-size: 0.78rem; font-weight: 800; color: var(--text-dim); width: 16px; flex-shrink: 0;">#{{ $i + 1 }}</span>
                                <div style="min-width: 0;">
                                    <a href="{{ route('videos.show', $pub->video) }}" style="font-size: 0.85rem; font-weight: 600; color: var(--text-main); display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $pub->video->title ?? 'Untitled Short' }}</a>
                                    <div style="font-size: 0.72rem; color: var(--text-dim);">{{ $pub->socialAccount->account_name ?? 'YouTube' }}</div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px; flex-shrink: 0; align-items: center;">
                                <span class="badge" style="background: rgba(16,185,129,0.12); color: #34d399; border: 1px solid rgba(16,185,129,0.25); font-size: 0.7rem;">▶ {{ number_format((int) ($pub->analytics['views'] ?? 0)) }}</span>
                                <span class="badge" style="background: rgba(255,255,255,0.05); color: var(--text-dim); border: 1px solid var(--border-subtle); font-size: 0.7rem;">♥ {{ number_format((int) ($pub->analytics['likes'] ?? 0)) }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 20px 12px; color: var(--text-muted); font-size: 0.85rem;">
                    Published reels rank here by real views once stats arrive.
                </div>
            @endif
        </div>
    </div>
    </div>

    <!-- Right Column -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Scheduler / Cron Status -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Scheduler</h3>
                    <p class="card-subtitle">Auto-publish cron health</p>
                </div>
                @if($cronDisabled)
                    <span class="badge" style="background: rgba(251,191,36,0.12); color: #fbbf24; border: 1px solid rgba(251,191,36,0.3);">○ Disabled</span>
                @elseif($cronHealthy)
                    <span class="badge" style="background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3);">● Running</span>
                @elseif($lastCronCheckAt)
                    <span class="badge" style="background: rgba(248,113,113,0.12); color: #f87171; border: 1px solid rgba(248,113,113,0.3);">● Not running</span>
                @else
                    <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);">Never ran</span>
                @endif
            </div>
            <div class="card-body" style="padding: 14px 16px;">
                <div style="display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem;">
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Last checked</span>
                        <strong>{{ $lastCronCheckAt ? $lastCronCheckAt->diffForHumans() . ' · ' . $lastCronCheckAt->format('h:i A') . ' ' . now()->format('P') : 'Never' }}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Runs every</span>
                        <strong>1 minute (auto-publish)</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: var(--text-dim);">Queued posts</span>
                        <strong>{{ $scheduledCount }}</strong>
                    </div>
                </div>
                @if($cronDisabled)
                    <div style="font-size: 0.75rem; color: #fbbf24; margin-top: 10px;">
                        Auto-publishing is paused in <a href="{{ route('settings.index') }}" style="color: var(--primary); text-decoration: underline;">Settings</a> — enable it to resume scheduled uploads.
                    </div>
                @elseif(!$cronHealthy)
                    <div style="font-size: 0.75rem; color: #f87171; margin-top: 10px;">
                        @if($lastCronCheckAt)
                            Scheduler hasn't checked in for {{ $lastCronCheckAt->diffForHumans() }}. Install it with <code style="font-size: 0.7rem;">php artisan cron:install</code> or keep <code style="font-size: 0.7rem;">php artisan schedule:work</code> running.
                        @else
                            The scheduler has never run. Install it with <code style="font-size: 0.7rem;">php artisan cron:install</code>.
                        @endif
                    </div>
                @endif
                <form method="POST" action="{{ route('dashboard.cron.run') }}" style="margin-top: 12px;">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Run Now</button>
                </form>
            </div>
        </div>

        <!-- Connected YouTube -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">YouTube Channels</h3>
                    <p class="card-subtitle">Where scheduled reels go live</p>
                </div>
                <a href="{{ route('accounts.index') }}" style="font-size: 0.82rem; font-weight: 600; color: var(--primary);">Manage</a>
            </div>
            <div class="card-body">
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    @forelse($youtubeAccounts as $acc)
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                                @if($acc->avatar)
                                    <img src="{{ $acc->avatar }}" alt="" style="width: 26px; height: 26px; border-radius: 50%;">
                                @else
                                    <div style="width: 26px; height: 26px; border-radius: 50%; background: #ef4444; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.7rem; color: white;">▶</div>
                                @endif
                                <div style="min-width: 0;">
                                    <div style="font-weight: 600; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $acc->account_name }}</div>
                                    <div style="font-size: 0.72rem; color: var(--text-dim);">{{ number_format($acc->follower_count) }} subscribers</div>
                                </div>
                            </div>
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: var(--accent-emerald);" title="Connected"></span>
                        </div>
                    @empty
                        <div style="text-align: center; padding: 20px 12px; color: var(--text-muted); font-size: 0.85rem;">
                            <div style="margin-bottom: 12px;">No YouTube channel connected yet.</div>
                            <a href="{{ route('accounts.youtube.connect') }}" class="btn btn-primary btn-sm">Connect with Google</a>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Upcoming Schedule Queue -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Next Scheduled Releases</h3>
                    <p class="card-subtitle">Queued for auto-publish</p>
                </div>
                <a href="{{ route('calendar.index') }}" style="font-size: 0.82rem; font-weight: 600; color: var(--primary);">Calendar →</a>
            </div>
            <div class="card-body" style="padding: 12px 16px;">
                @forelse($upcomingPublications as $pub)
                    <div style="padding: 10px 0; border-bottom: 1px solid var(--border-subtle); display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;">
                        <div>
                            <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-main);">{{ $pub->video->title ?? 'Untitled Short' }}</div>
                            <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 2px;">
                                YouTube · {{ $pub->scheduled_at ? $pub->scheduled_at->format('M d @ h:i A') : 'Queued' }}
                            </div>
                        </div>
                        <span class="badge badge-scheduled" style="font-size: 0.68rem;">Scheduled</span>
                    </div>
                @empty
                    <div style="text-align: center; padding: 20px 0; color: var(--text-muted); font-size: 0.85rem;">
                        No pending releases. Schedule reels from the Content Library.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
