@extends('layouts.app', ['title' => 'Select YouTube Channel'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Choose Your YouTube Channel</h1>
        <p>Google login successful. Pick which channel your reels should publish to.</p>
    </div>
</div>

<div style="max-width: 720px; margin: 0 auto;">
    @if($channels->isEmpty())
        <div class="card">
            <div class="card-body" style="text-align: center; padding: 48px;">
                <div style="font-size: 2rem; margin-bottom: 12px;">😕</div>
                <h3 style="margin-bottom: 8px;">No YouTube channels found</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 20px;">
                    This Google account doesn't manage any YouTube channels.
                </p>
                <a href="{{ route('accounts.index') }}" class="btn btn-secondary">Back to Accounts</a>
            </div>
        </div>
    @else
        <div style="display: flex; flex-direction: column; gap: 16px;">
            @foreach($channels as $c)
                <div class="card">
                    <div class="card-body" style="padding: 20px 24px; display: flex; align-items: center; gap: 16px;">
                        @if($c['thumbnail'])
                            <img src="{{ $c['thumbnail'] }}" alt="{{ $c['title'] }}" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; flex-shrink: 0;">
                        @else
                            <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(239, 68, 68, 0.2); color: #ef4444; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.3rem; flex-shrink: 0;">▶</div>
                        @endif
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 700; font-size: 1rem;">{{ $c['title'] }}</div>
                            <div style="font-size: 0.8rem; color: var(--text-dim);">
                                {{ $c['custom_url'] ?? 'YouTube Channel' }}
                                <span style="margin: 0 6px;">•</span>
                                {{ number_format($c['subscribers']) }} subscribers
                                <span style="margin: 0 6px;">•</span>
                                {{ number_format($c['video_count']) }} videos
                            </div>
                        </div>
                        <form method="POST" action="{{ route('accounts.youtube.select') }}">
                            @csrf
                            <input type="hidden" name="channel_id" value="{{ $c['id'] }}">
                            <button type="submit" class="btn btn-primary">
                                Use This Channel →
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
