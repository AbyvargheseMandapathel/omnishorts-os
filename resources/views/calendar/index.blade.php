@extends('layouts.app', ['title' => 'Schedule Calendar'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h1>Publication Calendar</h1>
            <span class="badge badge-scheduled" style="text-transform: none; font-weight: 600;">
                {{ $upcomingQueue->count() }} Posts Queued
            </span>
            <span class="badge" style="background: rgba(255,255,255,0.06); color: #b9b9bd; border: 1px solid rgba(255,255,255,0.14); text-transform: none; font-weight: 600;">
                ⏰ {{ $channel->scheduleLabel() }}
            </span>
        </div>
        <p>Automated YouTube scheduling. {{ $channel->name }}'s default cron: <strong style="color: var(--text-main);">{{ $channel->scheduleLabel() }}</strong>. Drag posts between days, or drag unscheduled reels onto the grid.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" data-open-modal="postScheduleModal">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            <span>Default Cron</span>
        </button>
        <a href="{{ route('videos.create') }}" class="btn btn-primary">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
            <span>Upload Reel</span>
        </a>
    </div>
</div>

<!-- Calendar Main Workspace -->
<div class="card" style="margin-bottom: 24px; border: 1px solid var(--border-card); box-shadow: var(--shadow-lg);">
    <!-- Calendar Controls Bar -->
    <div class="card-header" style="background: rgba(11, 15, 25, 0.9); padding: 14px 22px; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <div style="display: flex; align-items: center; gap: 6px;">
                <a href="{{ route('calendar.index', ['date' => $currentMonth->copy()->subMonth()->toDateString()]) }}" class="btn btn-secondary btn-sm" style="padding: 6px 10px;" title="Previous Month">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </a>
                <a href="{{ route('calendar.index', ['date' => now()->toDateString()]) }}" class="btn btn-secondary btn-sm" style="font-weight: 700;">Today</a>
                <a href="{{ route('calendar.index', ['date' => $currentMonth->copy()->addMonth()->toDateString()]) }}" class="btn btn-secondary btn-sm" style="padding: 6px 10px;" title="Next Month">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </a>
            </div>
            <h2 style="font-size: 1.25rem; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;">
                {{ $currentMonth->format('F Y') }}
            </h2>
        </div>

        <!-- Platform Legend -->
        <div style="display: flex; align-items: center; gap: 12px; font-size: 0.78rem;">
            <div style="display: flex; align-items: center; gap: 6px; padding: 4px 10px; background: rgba(239, 68, 68, 0.12); border-radius: 999px; color: #fca5a5; font-weight: 600;">
                <span style="width: 7px; height: 7px; border-radius: 50%; background: #ef4444;"></span>
                <span>YouTube</span>
            </div>
        </div>
    </div>

    <!-- Month Grid (always 7 columns, compact on mobile) -->
    <div class="card-body" style="padding: 0;">
        <div class="calendar-grid">
            <div class="calendar-header-cell">Sun</div>
            <div class="calendar-header-cell">Mon</div>
            <div class="calendar-header-cell">Tue</div>
            <div class="calendar-header-cell">Wed</div>
            <div class="calendar-header-cell">Thu</div>
            <div class="calendar-header-cell">Fri</div>
            <div class="calendar-header-cell">Sat</div>

            @foreach($calendarDays as $cell)
                @php
                    $pubCount = $cell['publications']->count();
                    $visiblePubs = $cell['publications']->take(3);
                    $overflowCount = max(0, $pubCount - 3);
                @endphp
                <div class="calendar-day-cell {{ !$cell['isCurrentMonth'] ? 'other-month' : '' }} {{ $cell['isToday'] ? 'is-today' : '' }}" data-date="{{ $cell['date']->toDateString() }}">
                    <div class="day-cell-top">
                        <span class="day-number">{{ $cell['date']->format('j') }}</span>
                        @if($cell['isToday'])
                            <span class="day-today-tag">Today</span>
                        @endif
                        @if($pubCount > 0)
                            <span class="day-count">{{ $pubCount }}</span>
                        @endif
                    </div>

                    @if($pubCount > 0)
                        <div class="day-chips">
                            @foreach($visiblePubs as $pub)
                                @php
                                    $timeStr = $pub->scheduled_at ? $pub->scheduled_at->format('h:i A') : '';
                                    $draggable = $pub->status === 'scheduled';
                                @endphp
                                <a href="{{ route('videos.show', $pub->video) }}" class="calendar-post-chip yt {{ $pub->status === 'published' ? 'is-live' : '' }}"
                                    @if($draggable)
                                        draggable="true"
                                        data-pub-id="{{ $pub->id }}"
                                        title="Drag to another day — {{ $pub->video->title ?? 'Short' }} ({{ $timeStr }})"
                                    @else
                                        title="{{ $pub->video->title ?? 'Short' }} ({{ $timeStr }}) — {{ ucfirst($pub->status) }}"
                                    @endif
                                >
                                    <span class="chip-status-dot">
                                        @if($pub->status === 'published')
                                            ✓
                                        @else
                                            ●
                                        @endif
                                    </span>
                                    <span class="chip-time">{{ $timeStr }}</span>
                                    <span class="chip-title">
                                        {{ $pub->video->title ?? 'Short' }}
                                    </span>
                                </a>
                            @endforeach

                            @if($overflowCount > 0)
                                <span class="chip-more" title="{{ $overflowCount }} more scheduled this day">+{{ $overflowCount }} more</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>

<!-- Unscheduled Reels Tray -->
@if($unscheduledVideos->isNotEmpty())
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <div>
            <h3 class="card-title">Unscheduled Reels</h3>
            <p class="card-subtitle">Drag a reel onto a day to queue it into that day's first free posting slot</p>
        </div>
        <span class="badge badge-ready">{{ $unscheduledVideos->count() }} Ready</span>
    </div>
    <div class="card-body" style="padding: 16px 20px; display: flex; gap: 10px; flex-wrap: wrap;">
        @foreach($unscheduledVideos as $video)
            <div class="tray-chip" data-video-id="{{ $video->id }}" draggable="true" title="Drag onto a day to schedule">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="white" opacity="0.85"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $video->title }}</span>
            </div>
        @endforeach
    </div>
</div>
@endif

<!-- Upcoming Dispatch Queue -->
<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title">Scheduled Release Pipeline</h3>
            <p class="card-subtitle">Upcoming automated YouTube releases in chronological order</p>
        </div>
        <span class="badge badge-ready">{{ $upcomingQueue->count() }} In Queue</span>
    </div>
    <div class="card-body" style="padding: 0;">
        @if($upcomingQueue->isEmpty())
            <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.04); display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; color: var(--text-dim);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"></rect><line x1="16" x2="16" y1="2" y2="6"></line><line x1="8" x2="8" y1="2" y2="6"></line><line x1="3" x2="21" y1="10" y2="10"></line></svg>
                </div>
                <div style="font-weight: 600; font-size: 0.95rem; color: var(--text-main);">No posts scheduled right now</div>
                <p style="font-size: 0.82rem; margin-top: 4px;">Pick a video from your content library to schedule automated releases.</p>
                <a href="{{ route('videos.index') }}" class="btn btn-secondary btn-sm" style="margin-top: 14px;">Browse Content Library</a>
            </div>
        @else
            <div style="display: flex; flex-direction: column;">
                @foreach($upcomingQueue as $item)
                    <div class="hover-row" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 22px; border-bottom: 1px solid var(--border-subtle); gap: 16px;">
                        <div style="display: flex; align-items: center; gap: 14px; min-width: 0;">
                            <div style="width: 34px; height: 34px; border-radius: 9px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.25); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; color: #fca5a5; flex-shrink: 0;">
                                YT
                            </div>
                            <div style="min-width: 0;">
                                <a href="{{ route('videos.show', $item->video) }}" style="font-weight: 700; font-size: 0.92rem; color: var(--text-main); display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ $item->video->title ?? 'Untitled Short' }}
                                </a>
                                <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 2px;">
                                    Target: <strong style="color: var(--text-muted);">{{ $item->socialAccount->account_name ?? 'Social Account' }}</strong>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; gap: 14px; flex-shrink: 0;">
                            <div style="text-align: right;">
                                <div style="font-size: 0.85rem; font-weight: 700; color: var(--accent-cyan);">
                                    {{ $item->scheduled_at ? $item->scheduled_at->format('M d, Y') : 'Queued' }}
                                </div>
                                <div style="font-size: 0.72rem; color: var(--text-dim);">
                                    {{ $item->scheduled_at ? $item->scheduled_at->format('h:i A') : '' }}
                                </div>
                            </div>
                            <a href="{{ route('videos.show', $item->video) }}" class="btn btn-secondary btn-sm">Edit</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<!-- Posting Schedule Modal -->
<div class="modal-backdrop" id="postScheduleModal">
    <div class="modal-dialog">
        <div class="card-header">
            <div>
                <h3 class="card-title">Default Cron — {{ $channel->name }}</h3>
                <p class="card-subtitle">Channel-wide fallback. Each connected YouTube channel can override this with its own cron on the <a href="{{ route('accounts.index') }}" style="color: var(--primary); text-decoration: underline;">YouTube Channels</a> page — accounts without their own cron use these slots.</p>
            </div>
            <button type="button" data-close-modal="postScheduleModal" style="background: transparent; border: none; color: var(--text-dim); cursor: pointer; font-size: 1.2rem;">✕</button>
        </div>
        <form method="POST" action="{{ route('channels.schedule.update', $channel) }}">
            @csrf
            @method('PUT')
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Uploads Per Day</label>
                    <select name="posts_per_day" id="postsPerDaySelect" class="form-select">
                        @for($i = 1; $i <= 10; $i++)
                            <option value="{{ $i }}" {{ ($channel->posts_per_day ?? 1) == $i ? 'selected' : '' }}>{{ $i }} post{{ $i > 1 ? 's' : '' }} / day</option>
                        @endfor
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Post Times</label>
                    <div id="postTimesList" style="display: flex; flex-direction: column; gap: 10px;">
                        @foreach($channel->postingTimes() as $index => $time)
                            <div class="post-time-row" style="display: flex; align-items: center; gap: 10px;">
                                <span style="width: 26px; height: 26px; border-radius: 50%; background: rgba(255,255,255,0.07); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.78rem; flex-shrink: 0;">#{{ $index + 1 }}</span>
                                <input type="time" name="post_times[]" class="form-input" value="{{ $time }}" style="max-width: 200px;">
                            </div>
                        @endforeach
                    </div>
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 6px;">This is {{ $channel->name }}'s default cron. Bulk uploads queue reels into the selected YouTube channel's slots in order; single videos default to that channel's next free slot.</span>
                </div>
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn btn-secondary" data-close-modal="postScheduleModal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Schedule</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/calendar.js') }}"></script>
@endpush
