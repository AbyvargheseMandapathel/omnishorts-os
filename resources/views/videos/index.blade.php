@extends('layouts.app', ['title' => 'Content Library'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Content Library</h1>
        <p>Upload, review, and schedule your reels for YouTube.</p>
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

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('videos.index') }}" style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 240px;">
                <input type="text" name="search" class="form-input" placeholder="Search by title, keywords..." value="{{ request('search') }}">
            </div>

            <div style="width: 180px;">
                <select name="status" class="form-select" data-submit-on-change>
                    <option value="all">All Statuses</option>
                    <option value="ready" {{ request('status') == 'ready' ? 'selected' : '' }}>Ready to Post</option>
                    <option value="scheduled" {{ request('status') == 'scheduled' ? 'selected' : '' }}>Scheduled</option>
                    <option value="published" {{ request('status') == 'published' ? 'selected' : '' }}>Published</option>
                    <option value="processing" {{ request('status') == 'processing' ? 'selected' : '' }}>Processing</option>
                    <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Drafts</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'status']))
                <a href="{{ route('videos.index') }}" class="btn btn-outline">Clear</a>
            @endif
        </form>
    </div>
</div>

<!-- Videos Grid -->
@if($videos->isEmpty())
    <div class="card" style="text-align: center; padding: 60px 20px;">
        <div style="width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 8-6 4 6 4V8Z"></path><rect width="14" height="12" x="2" y="6" rx="2"></rect></svg>
        </div>
        <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 8px;">No reels found</h3>
        <p style="color: var(--text-muted); font-size: 0.92rem; max-width: 420px; margin: 0 auto 24px;">Upload your edited reels, or import a whole bundle and we'll queue them daily.</p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('videos.bulk') }}" class="btn btn-secondary">Import Bundle</a>
            <a href="{{ route('videos.create') }}" class="btn btn-primary">Upload Reel</a>
        </div>
    </div>
@else
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px;">
        @foreach($videos as $video)
            <div class="card" style="display: flex; flex-direction: column; position: relative;">
                @php($playUrl = $video->playbackUrl())
                <!-- 9:16 Thumbnail Container -->
                <div class="library-thumb" style="position: relative; width: 100%; aspect-ratio: 9/12; background: linear-gradient(180deg, #141417 0%, #0b0b0d 100%); overflow: hidden; display: flex; align-items: center; justify-content: center;">
                    @if($playUrl)
                        <video class="library-video" src="{{ $playUrl }}" preload="metadata" playsinline style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 1;"></video>
                    @endif

                    @if(isset($video->ai_data['hook']))
                        <div style="position: absolute; top: 18px; left: 12px; right: 12px; text-align: center; z-index: 5;">
                            <span style="background: rgba(10,10,10,0.85); color: #fff; font-size: 0.72rem; font-weight: 700; padding: 4px 10px; border-radius: 999px; display: inline-block; border: 1px solid rgba(255,255,255,0.22); box-shadow: 0 4px 10px rgba(0,0,0,0.4);">
                                {{ Str::limit($video->ai_data['hook'], 45) }}
                            </span>
                        </div>
                    @endif

                    <button type="button" class="play-overlay" aria-label="Play video" style="width: 52px; height: 52px; border-radius: 50%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; color: white; border: 1px solid rgba(255,255,255,0.2); cursor: pointer; z-index: 4; padding: 0;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="white"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                    </button>

                    <span style="position: absolute; bottom: 10px; right: 10px; background: rgba(0,0,0,0.75); color: white; font-size: 0.72rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; z-index: 4;">
                        @if($video->duration)0:{{ str_pad($video->duration, 2, '0', STR_PAD_LEFT) }}@else—@endif
                    </span>

                    <div style="position: absolute; top: 10px; left: 10px; z-index: 4;">
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

                <!-- Card Body -->
                <div style="padding: 16px; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <h4 style="font-size: 0.95rem; font-weight: 700; margin-bottom: 6px; line-height: 1.35; color: var(--text-main);">
                            <a href="{{ route('videos.show', $video) }}">{{ $video->title }}</a>
                        </h4>

                        @if(isset($video->ai_data['virality_score']))
                            <div style="display: flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 700; color: var(--text-dim); margin-bottom: 8px;">
                                <span style="color: var(--primary);">★</span> Viral Score <span style="color: var(--text-main);">{{ $video->ai_data['virality_score'] }}/100</span>
                            </div>
                        @endif

                        @if($video->publications->isNotEmpty())
                            <div style="display: flex; gap: 4px; margin-top: 6px; flex-wrap: wrap;">
                                @foreach($video->publications as $pub)
                                    @if(($pub->socialAccount->platform ?? '') === 'youtube')
                                        <span style="font-size: 0.68rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; background: rgba(239, 68, 68, 0.15); color: #fca5a5;">
                                            YouTube · {{ ucfirst($pub->status) }}
                                        </span>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 16px; border-top: 1px solid var(--border-subtle); padding-top: 12px;">
                        <a href="{{ route('videos.show', $video) }}" class="btn btn-primary btn-sm" style="flex: 1;">
                            Review & Schedule
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Pagination -->
    <div>
        {{ $videos->links() }}
    </div>
@endif
@endsection
