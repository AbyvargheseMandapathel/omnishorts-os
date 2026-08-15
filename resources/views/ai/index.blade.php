@extends('layouts.app', ['title' => 'AI Video Jobs'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>AI Video Jobs</h1>
        <p>Track and manage your AI-generated videos.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('ai.videos.create') }}" class="btn btn-primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
            <span>New AI Video</span>
        </a>
    </div>
</div>

@if($jobs->isEmpty())
    <div class="card" style="text-align: center; padding: 60px 20px;">
        <div style="width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a4 4 0 0 1 4 4c0 .6-.13 1.17-.36 1.68A4 4 0 0 1 20 11c0 .4-.05.78-.15 1.15A4 4 0 0 1 21 16a4 4 0 0 1-4 4c-.5 0-.98-.09-1.43-.25A4 4 0 0 1 12 22a4 4 0 0 1-3.57-2.25A4 4 0 0 1 7 20a4 4 0 0 1-4-4c0-.4.05-.78.15-1.15A4 4 0 0 1 3 11c0-.85.27-1.63.72-2.27A4 4 0 0 1 8 6c.6 0 1.17.13 1.68.36A4 4 0 0 1 12 2Z"></path><path d="M9 12h6"></path><path d="M12 9v6"></path></svg>
        </div>
        <h3 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 8px;">No AI videos yet</h3>
        <p style="color: var(--text-muted); font-size: 0.92rem; max-width: 420px; margin: 0 auto 24px;">Upload a background video, describe the topic, and the AI pipeline builds the whole reel — script, scenes, voice, captions, render.</p>
        <a href="{{ route('ai.videos.create') }}" class="btn btn-primary">Create AI Video</a>
    </div>
@else
    <div class="card">
        <div class="card-body" style="padding: 8px 12px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="color: var(--text-dim); text-align: left;">
                        <th style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle);">Job</th>
                        <th style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle);">Type</th>
                        <th style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle);">Status</th>
                        <th style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle);">Created</th>
                        <th style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle);"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($jobs as $job)
                        <tr>
                            <td style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle);">
                                <a href="{{ route('ai.videos.show', $job) }}" style="font-weight: 600; color: var(--text-main); text-decoration: none;">#{{ $job->id }} — {{ \Illuminate\Support\Str::limit($job->topic, 60) }}</a>
                                @if($job->video)
                                    <span style="display: block; font-size: 0.72rem; color: var(--text-dim);">approved → <a href="{{ route('videos.show', $job->video) }}" style="color: var(--primary);">{{ $job->video->title }}</a></span>
                                @endif
                            </td>
                            <td style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle); text-transform: capitalize;">{{ $job->content_type }}</td>
                            <td style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle);">
                                @if($job->status === 'completed')
                                    <span class="badge badge-ready">Completed</span>
                                @elseif($job->status === 'failed')
                                    <span class="badge badge-draft">Failed</span>
                                @elseif($job->status === 'cancelled')
                                    <span class="badge">Cancelled</span>
                                @else
                                    <span class="badge badge-processing">{{ ucfirst($job->status) }}{{ $job->stage_label ? ' · '.$job->stage_label : '' }}</span>
                                @endif
                            </td>
                            <td style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle); color: var(--text-dim); font-size: 0.78rem;">{{ $job->created_at->diffForHumans() }}</td>
                            <td style="padding: 10px 12px; border-bottom: 1px solid var(--border-subtle); text-align: right;">
                                <a href="{{ route('ai.videos.show', $job) }}" class="btn btn-secondary btn-sm">Open</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div style="margin-top: 18px;">
        {{ $jobs->links() }}
    </div>
@endif
@endsection
