@extends('layouts.app', ['title' => 'Review & Schedule Reel'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
            <a href="{{ route('videos.index') }}" style="color: var(--text-dim); font-size: 0.85rem; font-weight: 600;">← Back to Library</a>
        </div>
        <h1>{{ $video->title }}</h1>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('videos.destroy', $video) }}" data-confirm="Are you sure you want to delete this reel?">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm">Delete Reel</button>
        </form>
    </div>
</div>

<div class="video-grid">
    <!-- Left Column: 9:16 Vertical Video Preview -->
    <div style="display: flex; flex-direction: column; align-items: center; gap: 16px;">
        @if($videoUrl)
            <div class="mockup-phone" style="overflow: hidden;">
                <video controls playsinline preload="metadata" src="{{ $videoUrl }}" style="flex: 1; width: 100%; height: 100%; object-fit: cover; background: #000;">
                    Your browser does not support video playback.
                </video>
            </div>
        @else
        <div class="mockup-phone">
            <div class="mockup-screen">
                <div class="mockup-hook-overlay">
                    <span class="mockup-hook-text" id="liveHookPreview">
                        {{ $video->ai_data['hook'] ?? '🔥 ' . $video->title }}
                    </span>
                </div>

                <div class="mockup-actions-bar">
                    <div class="mockup-action-btn">
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="white"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                        </div>
                        <span>48.2K</span>
                    </div>

                    <div class="mockup-action-btn">
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                        <span>1.4K</span>
                    </div>

                    <div class="mockup-action-btn">
                        <div style="width: 38px; height: 38px; border-radius: 50%; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"></line><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"></line></svg>
                        </div>
                        <span>Share</span>
                    </div>
                </div>

                <div class="mockup-bottom-info">
                    <div class="mockup-creator-handle">
                        {{ '@' . ($channel->handle ?? 'creator') }}
                    </div>
                    <div class="mockup-caption" id="liveCaptionPreview">
                        {{ $video->ai_data['caption'] ?? $video->description }}
                    </div>
                    <div style="font-size: 0.72rem; color: #c4c2c3; margin-top: 4px; font-weight: 600;" id="liveHashtagPreview">
                        {{ $video->ai_data['hashtags'] ?? '#shorts #viral' }}
                    </div>
                </div>
            </div>
        </div>
        @endif

        <div style="display: flex; gap: 8px;">
            <span class="badge badge-ready">9:16 Vertical</span>
            <span class="badge badge-ai">@if($video->duration)0:{{ str_pad($video->duration, 2, '0', STR_PAD_LEFT) }}@else—@endif</span>
            @if(isset($video->ai_data['virality_score']))
                <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                    ★ {{ $video->ai_data['virality_score'] }}% Viral Score
                </span>
            @endif
        </div>
    </div>

    <!-- Right Column -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Publish / Schedule -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Schedule to YouTube</h3>
                    <p class="card-subtitle">Publish now or queue it — scheduled reels go live automatically</p>
                </div>
            </div>
            <div class="card-body">
                @if($youtubeAccounts->isEmpty())
                    <div style="text-align: center; padding: 24px;">
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 16px;">Connect a YouTube channel to publish this reel.</p>
                        <a href="{{ route('accounts.youtube.connect') }}" class="btn btn-primary">Connect with Google →</a>
                    </div>
                @else
                    <form method="POST" action="{{ route('videos.publish', $video) }}">
                        @csrf

                        <div class="form-group">
                            <label class="form-label">YouTube Channel</label>
                            <select name="account_id" class="form-select">
                                @foreach($youtubeAccounts as $acc)
                                    <option value="{{ $acc->id }}">{{ $acc->account_name }} ({{ $acc->handle }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Title & Caption</label>
                            <input type="text" name="custom_title" class="form-input" value="{{ $video->title }}" style="margin-bottom: 10px;">
                            <textarea name="custom_caption" class="form-textarea" rows="3" data-live-preview="#liveCaptionPreview">{{ $video->ai_data['caption'] ?? $video->description }}</textarea>
                            <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">Hashtags: {{ $video->ai_data['hashtags'] ?? '#shorts #viral' }}</span>
                        </div>

                        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.12); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 20px;">
                            <div style="display: flex; gap: 24px; margin-bottom: 16px;">
                                <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="action_type" value="schedule" checked data-toggles="#scheduleDatePicker" data-toggles-value="schedule">
                                    <span>📅 Schedule</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                                    <input type="radio" name="action_type" value="publish_now" data-toggles="#scheduleDatePicker" data-toggles-value="publish_now">
                                    <span>🚀 Publish Now</span>
                                </label>
                            </div>

                            <div id="scheduleDatePicker" class="form-group" style="margin-bottom: 0;">
                                <label class="form-label">Date & Time (override — defaults to cron)</label>
                                <input type="datetime-local" name="scheduled_at" class="form-input" value="{{ $nextFreeSlot->format('Y-m-d\TH:i') }}" style="max-width: 320px;">
                                <span style="font-size: 0.78rem; color: var(--accent-emerald); display: block; margin-top: 4px;">
                                    ⏰ Channel cron: {{ $channel->scheduleLabel() }} — next free slot is {{ $nextFreeSlot->format('D, M d @ h:i A') }}. Clear this field to auto-use the cron.
                                </span>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end;">
                            <button type="submit" class="btn btn-primary btn-lg">
                                {{ $video->status === 'scheduled' || $video->publications->isNotEmpty() ? 'Update Schedule 🚀' : 'Queue to YouTube 🚀' }}
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        <!-- Reupload published reel -->
        @if($video->publications->where('status', 'published')->isNotEmpty())
            <div class="card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Reupload to YouTube</h3>
                        <p class="card-subtitle">Publish this reel again — creates a fresh YouTube video (previous one stays live)</p>
                    </div>
                </div>
                <div class="card-body">
                    @if($youtubeAccounts->isEmpty())
                        <p style="color: var(--text-muted); font-size: 0.88rem;">Connect a YouTube channel to reupload this reel.</p>
                    @else
                        <form method="POST" action="{{ route('videos.reupload', $video) }}">
                            @csrf
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; align-items: end;">
                                <div>
                                    <label class="form-label">YouTube Channel</label>
                                    <select name="account_id" class="form-select">
                                        @foreach($youtubeAccounts as $acc)
                                            <option value="{{ $acc->id }}">{{ $acc->account_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Upload Time</label>
                                    <input type="datetime-local" name="scheduled_at" class="form-input" value="{{ now()->format('Y-m-d\TH:i') }}">
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary btn-sm">Queue Reupload</button>
                                </div>
                            </div>
                            <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 6px;">Blank = uploads on the next cron tick. Uses AI-generated metadata when available.</span>
                            @error('youtube')
                                <span style="font-size: 0.8rem; color: #f87171; display: block; margin-top: 8px;">{{ $message }}</span>
                            @enderror
                        </form>
                        @if($video->publications->where('status', 'published')->isNotEmpty())
                            <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border-subtle);">
                                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px;">Published Versions</div>
                                @foreach($video->publications->where('status', 'published') as $pub)
                                    <div style="display: flex; align-items: center; gap: 8px; font-size: 0.82rem; padding: 3px 0;">
                                        <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399;">●</span>
                                        <span style="color: var(--text-dim);">{{ $pub->published_at ? $pub->published_at->format('M d, h:i A') : '—' }}</span>
                                        @if($pub->post_url)
                                            <a href="{{ $pub->post_url }}" target="_blank" rel="noopener" style="color: var(--primary); text-decoration: underline;">{{ $pub->post_url }}</a>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endif

        <!-- AI Analysis -->
        <div class="card">
            <div class="card-header">
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <div>
                        <h3 class="card-title">AI Analysis</h3>
                        <p class="card-subtitle">Gemini-generated metadata for the YouTube upload</p>
                    </div>
                    @if($video->analysis_status === 'completed')
                        <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);">AI Analysis: Completed</span>
                    @elseif($video->analysis_status === 'failed')
                        <span class="badge" style="background: rgba(248, 113, 113, 0.12); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.3);">AI Analysis: Failed</span>
                    @elseif($video->analysis_status === 'processing')
                        <span class="badge badge-ai">AI Analysis: Processing…</span>
                    @else
                        <span class="badge" style="background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);">AI Analysis: Pending</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @if($video->analysis_status === 'completed' && $video->ai_title)
                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">🔥 Hook</div>
                            <div style="display: flex; align-items: flex-start; gap: 8px;">
                                <span style="flex: 1;">{{ $video->ai_hook }}</span>
                                <button type="button" class="btn btn-secondary btn-sm" data-copy="{{ $video->ai_hook }}">Copy</button>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">🎯 Title</div>
                            <div style="display: flex; align-items: flex-start; gap: 8px;">
                                <span style="flex: 1;">{{ $video->ai_title }}</span>
                                <button type="button" class="btn btn-secondary btn-sm" data-copy="{{ $video->ai_title }}">Copy</button>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">📝 Description</div>
                            <div style="display: flex; align-items: flex-start; gap: 8px;">
                                <span style="flex: 1;">{{ $video->ai_description }}</span>
                                <button type="button" class="btn btn-secondary btn-sm" data-copy="{{ $video->ai_description }}">Copy</button>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">#️⃣ Hashtags</div>
                            <div style="display: flex; align-items: flex-start; gap: 8px;">
                                <span style="flex: 1;">#{{ implode(' #', $video->ai_hashtags ?? []) }}</span>
                                <button type="button" class="btn btn-secondary btn-sm" data-copy="#{{ implode(' #', $video->ai_hashtags ?? []) }}">Copy</button>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">🖼️ Thumbnail Text</div>
                            <div style="display: flex; align-items: flex-start; gap: 8px;">
                                <span style="flex: 1;">{{ $video->ai_thumbnail_text }}</span>
                                <button type="button" class="btn btn-secondary btn-sm" data-copy="{{ $video->ai_thumbnail_text }}">Copy</button>
                            </div>
                        </div>
                        @if($video->ai_best_moment && ($video->ai_best_moment['start'] ?? ''))
                            <div>
                                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">⏱️ Best Moment</div>
                                <div style="font-size: 0.85rem;">
                                    <strong>{{ $video->ai_best_moment['start'] }} – {{ $video->ai_best_moment['end'] }}</strong>
                                    <span style="color: var(--text-dim);">— {{ $video->ai_best_moment['reason'] }}</span>
                                </div>
                            </div>
                        @endif
                        <div style="display: flex; gap: 24px; flex-wrap: wrap; padding-top: 4px;">
                            <div>
                                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px;">📊 Virality Score</div>
                                <div style="font-size: 1.4rem; font-weight: 800; color: var(--accent-emerald);">{{ $video->ai_virality_score }}<span style="font-size: 0.85rem; color: var(--text-dim);">/100</span></div>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px;">Category</div>
                                <div style="font-weight: 600;">{{ $video->ai_category }}</div>
                            </div>
                            <div>
                                <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px;">Target Audience</div>
                                <div style="font-weight: 600;">{{ $video->ai_target_audience }}</div>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;">💡 Improvement</div>
                            <div style="font-size: 0.85rem;">{{ $video->ai_improvement }}</div>
                        </div>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding-top: 6px; border-top: 1px solid var(--border-subtle);">
                            <span style="font-size: 0.72rem; color: var(--text-dim);">
                                Model: <code style="font-size: 0.7rem;">{{ $video->model_used }}</code>
                                @if($video->analyzed_at)
                                    · {{ $video->analyzed_at->format('M d, Y h:i A') }}
                                @endif
                            </span>
                            @php
                                $copyAll = implode("\n\n", array_filter([
                                    'Hook: ' . $video->ai_hook,
                                    'Title: ' . $video->ai_title,
                                    'Description: ' . $video->ai_description,
                                    'Hashtags: #' . implode(' #', $video->ai_hashtags ?? []),
                                    'Thumbnail Text: ' . $video->ai_thumbnail_text,
                                    'Virality Score: ' . $video->ai_virality_score . '/100',
                                    'Improvement: ' . $video->ai_improvement,
                                ]));
                            @endphp
                            <button type="button" class="btn btn-secondary btn-sm" data-copy="{{ $copyAll }}">Copy All</button>
                        </div>
                    </div>
                @else
                    <div style="text-align: center; padding: 20px 8px;">
                        <p style="color: var(--text-muted); font-size: 0.88rem; margin-bottom: 16px;">
                            @if($video->analysis_status === 'failed')
                                Last analysis attempt failed — the scheduled upload will use the existing metadata. Try again or fix Gemini in <a href="{{ route('settings.index') }}" style="color: var(--primary); text-decoration: underline;">Settings</a>.
                            @else
                                Run Gemini to generate a hook, title, description, hashtags, thumbnail text, and virality score for the YouTube upload.
                            @endif
                        </p>
                        <form method="POST" action="{{ route('videos.analyze', $video) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm" {{ !$geminiEnabled ? 'disabled' : '' }}>Analyze with Gemini</button>
                        </form>
                        @if(!$geminiEnabled)
                            <span style="font-size: 0.75rem; color: var(--text-dim); display: block; margin-top: 8px;">Gemini AI is disabled — <a href="{{ route('settings.index') }}" style="color: var(--primary); text-decoration: underline;">enable it in Settings</a>.</span>
                        @endif
                        @error('gemini')
                            <span style="font-size: 0.8rem; color: #f87171; display: block; margin-top: 10px;">{{ $message }}</span>
                        @enderror
                    </div>
                @endif
            </div>
        </div>

        <!-- Performance: real YouTube stats for this reel -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Performance</h3>
                    <p class="card-subtitle">
                        Real YouTube stats · refreshed twice daily
                        @if($statsLastRefreshedAt)
                            · last refreshed <strong style="color: {{ $statsLastRefreshedAt->lt(now()->subHours(24)) ? '#fbbf24' : 'var(--text-dim)' }};">{{ $statsLastRefreshedAt->diffForHumans() }}</strong>
                        @else
                            · not refreshed yet
                        @endif
                    </p>
                </div>
                @if($video->publications->where('status', 'published')->isNotEmpty())
                    <form method="POST" action="{{ route('videos.refresh-stats', $video) }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">↻ Refresh Stats</button>
                    </form>
                @endif
            </div>
            <div class="card-body">
                @error('stats')
                    <div style="font-size: 0.8rem; color: #f87171; margin-bottom: 12px;">{{ $message }}</div>
                @enderror
                @if($videoViews + $videoLikes + $videoComments + $videoShares > 0)
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
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 16px;">
                        <div style="padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em;">Views</div>
                            <div style="font-size: 1.25rem; font-weight: 800; margin-top: 2px;">{{ number_format($videoViews) }}</div>
                        </div>
                        <div style="padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em;">Likes</div>
                            <div style="font-size: 1.25rem; font-weight: 800; margin-top: 2px;">{{ number_format($videoLikes) }}</div>
                        </div>
                        <div style="padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em;">Comments</div>
                            <div style="font-size: 1.25rem; font-weight: 800; margin-top: 2px;">{{ number_format($videoComments) }}</div>
                        </div>
                        <div style="padding: 10px 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-subtle); border-radius: var(--radius-md);">
                            <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.04em;">Shares</div>
                            <div style="font-size: 1.25rem; font-weight: 800; margin-top: 2px;">{{ number_format($videoShares) }}</div>
                        </div>
                    </div>
                    @if($count > 1)
                        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px;">
                            <span style="font-size: 0.78rem; font-weight: 700; color: var(--text-dim);">Views — last {{ $count }} day{{ $count === 1 ? '' : 's' }}</span>
                            <span style="font-size: 0.78rem; font-weight: 700; color: var(--primary);">{{ number_format(max($curve)) }} peak</span>
                        </div>
                        <svg viewBox="0 0 100 30" preserveAspectRatio="none" style="width: 100%; height: 64px; display: block;" class="video-views-curve">
                            <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="var(--primary)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                        </svg>
                    @else
                        <div style="text-align: center; padding: 18px 12px; border: 1px dashed var(--border-subtle); border-radius: var(--radius-md); color: var(--text-muted); font-size: 0.8rem;">
                            Growth curve appears once the twice-daily refresh records multiple days of stats.
                        </div>
                    @endif
                @else
                    <div style="text-align: center; padding: 20px 12px; color: var(--text-muted); font-size: 0.85rem;">
                        No real stats yet — numbers appear here after this reel is published and the twice-daily YouTube refresh runs.
                    </div>
                @endif
            </div>
        </div>

        <!-- Video Details -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Reel Details</h3>
                    <p class="card-subtitle">Title, hook, and hashtags used for the post</p>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('videos.update', $video) }}">
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label class="form-label">Video Title</label>
                        <input type="text" name="title" class="form-input" value="{{ $video->title }}" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Hook Text (first seconds)</label>
                        <input type="text" name="hook" class="form-input" value="{{ $video->ai_data['hook'] ?? '' }}" data-live-preview="#liveHookPreview">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Hashtags</label>
                        <input type="text" name="hashtags" class="form-input" value="{{ $video->ai_data['hashtags'] ?? '' }}" data-live-preview="#liveHashtagPreview">
                    </div>

                    <div style="display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn btn-secondary">Save Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
