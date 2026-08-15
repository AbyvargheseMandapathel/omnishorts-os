@extends('layouts.app', ['title' => 'AI Video #'.$job->id])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1 style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            AI Video #{{ $job->id }}
            @if($job->status === 'completed')
                <span class="badge badge-ready">● Completed</span>
            @elseif($job->status === 'failed')
                <span class="badge badge-draft">● Failed</span>
            @elseif($job->status === 'cancelled')
                <span class="badge">Cancelled</span>
            @else
                <span class="badge badge-processing">● {{ ucfirst($job->status) }}</span>
            @endif
        </h1>
        <p>{{ Str::limit($job->topic, 140) }} · {{ ucfirst($job->content_type) }} · {{ $job->scenes_count }} scenes · {{ strtoupper($job->language) }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('ai.videos.index') }}" class="btn btn-secondary"><span>All Jobs</span></a>
        @if(in_array($job->status, ['queued', 'running'], true))
            <form method="POST" action="{{ route('ai.videos.cancel', $job) }}" data-confirm="Cancel this generation job?">
                @csrf
                <button type="submit" class="btn btn-danger">Cancel Job</button>
            </form>
        @endif
    </div>
</div>

@if($job->status === 'failed' && $job->error)
    <div class="alert alert-error" style="margin-bottom: 18px;">
        <div style="display: flex; align-items: flex-start; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" x2="12" y1="8" y2="12"></line><line x1="12" x2="12.01" y1="16" y2="16"></line></svg>
            <div>
                <strong style="font-size: 0.85rem;">Generation failed at {{ $job->stage_label ?: $job->stage }}</strong>
                <div style="font-size: 0.8rem; margin-top: 2px;">{{ $job->error }}</div>
                <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 6px;">Fix the cause (or pick a different provider) then retry that stage below — successful work is never redone.</div>
            </div>
        </div>
    </div>
@endif

<div id="aiJobStatus" data-progress-url="{{ route('ai.videos.progress', $job) }}"
     data-status="{{ $job->status }}"
     data-stage="{{ $job->stage }}"
     data-stage-label="{{ $job->stage_label }}"
     data-final-url="{{ $finalUrl ?? '' }}">

    <div style="display: grid; grid-template-columns: 1fr 400px; gap: 22px; align-items: start;">
        <div>
            {{-- Pipeline stages --}}
            <div class="card" style="margin-bottom: 22px;">
                <div class="card-body" style="padding: 20px 24px;">
                    <div style="font-weight: 700; font-size: 0.95rem; margin-bottom: 12px;">Pipeline</div>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        @foreach($stageLabels as $key => $label)
                            @php($st = ($job->progress['stages'][$key]['status'] ?? 'pending'))
                            <div class="ai-stage-row" data-stage="{{ $key }}" data-status="{{ $st }}" style="display: flex; align-items: center; gap: 10px; padding: 7px 10px; border-radius: 8px; {{ $st === 'running' ? 'background: rgba(59,130,246,0.08);' : '' }}">
                                <span class="ai-stage-icon" data-role="icon" style="width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; flex-shrink: 0; {{ $st === 'done' ? 'background: rgba(16,185,129,0.15); color: #34d399;' : ($st === 'failed' ? 'background: rgba(239,68,68,0.15); color: #f87171;' : ($st === 'running' ? 'background: rgba(59,130,246,0.15); color: #60a5fa;' : 'background: rgba(255,255,255,0.06); color: var(--text-dim);')) }}">
                                    @if($st === 'done')✓@elseif($st === 'failed')✗@elseif($st === 'running')●@else○@endif
                                </span>
                                <span style="flex: 1; font-size: 0.85rem; font-weight: 600;" data-role="label">{{ $label }}</span>
                                <span class="ai-stage-status" data-role="status" style="font-size: 0.75rem; color: var(--text-dim);">
                                    @if($st === 'done')Done@elseif($st === 'failed'){{ $job->progress['stages'][$key]['error'] ?? 'Failed' }}@elseif($st === 'running')Running…@elseWaiting@endif
                                </span>
                                @if($st === 'failed')
                                    <form method="POST" action="{{ route('ai.videos.retry', [$job, $key]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm">Retry</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if($job->providers_used)
                        <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border-subtle); display: flex; flex-wrap: wrap; gap: 8px;">
                            @foreach($job->providers_used as $kind => $info)
                                <span style="font-size: 0.72rem; color: var(--text-dim); background: rgba(255,255,255,0.04); border: 1px solid var(--border-subtle); padding: 4px 8px; border-radius: 6px;">
                                    {{ ucfirst($kind) }}: <strong style="color: var(--text-main);">{{ $info['provider'] }}</strong>@if($info['model']) · {{ $info['model'] }}@endif
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Scene images --}}
            <div class="card">
                <div class="card-body" style="padding: 20px 24px;">
                    <div style="font-weight: 700; font-size: 0.95rem; margin-bottom: 12px;">Scenes</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px;">
                        @foreach($job->scenes ?? [] as $scene)
                            @php($imgStatus = $scene['image_status'] ?? 'pending')
                            <div class="ai-scene-cell" data-scene="{{ $scene['scene_number'] }}" data-status="{{ $imgStatus }}" style="text-align: center;">
                                <div data-role="frame" style="aspect-ratio: 9/16; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-subtle); background: rgba(255,255,255,0.03); display: flex; align-items: center; justify-content: center; position: relative;">
                                    @if($imgStatus === 'done' && filled($scene['image_path'] ?? null))
                                        <img src="{{ route('ai.videos.image', [$job, $scene['scene_number']]) }}" alt="Scene {{ $scene['scene_number'] }}" style="width: 100%; height: 100%; object-fit: cover;">
                                    @else
                                        <span data-role="placeholder" style="font-size: 0.68rem; color: var(--text-dim); padding: 6px;">
                                            @if($imgStatus === 'failed')
                                                <span style="color: #f87171;">Failed<br><span style="font-size: 0.6rem;">{{ Str::limit($scene['image_error'] ?? '', 60) }}</span></span>
                                            @elseif($imgStatus === 'running')
                                                <span style="color: #60a5fa;">Generating…</span>
                                            @else
                                                Pending
                                            @endif
                                        </span>
                                    @endif
                                </div>
                                <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 6px;">Scene {{ $scene['scene_number'] }}
                                    @if(isset($scene['start_time'])) · {{ number_format($scene['start_time'], 1) }}s–{{ number_format($scene['end_time'], 1) }}s @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Preview / status column --}}
        <div>
            @if($job->status === 'completed' && $finalUrl)
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-body" style="padding: 16px;">
                        <div style="font-weight: 700; font-size: 0.95rem; margin-bottom: 12px;">Preview</div>
                        <video src="{{ $finalUrl }}" controls playsinline style="width: 100%; border-radius: 10px; background: #000;"></video>
                        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 14px;">
                            <a href="{{ route('ai.videos.edit', $job) }}" class="btn btn-secondary">✎ Edit / Regenerate</a>
                            <form method="POST" action="{{ route('ai.videos.approve', $job) }}" data-confirm="Approve this video? It moves to your Content Library where you can schedule and publish it.">
                                @csrf
                                <button type="submit" class="btn btn-primary" style="width: 100%;">✓ Approve & Send to Library</button>
                            </form>
                            <form method="POST" action="{{ route('ai.videos.retry', [$job, 'render']) }}">
                                @csrf
                                <button type="submit" class="btn btn-secondary" style="width: 100%;">↻ Re-render</button>
                            </form>
                        </div>
                    </div>
                </div>
            @elseif($job->status === 'failed')
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-body" style="padding: 16px;">
                        <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 10px;">Retry Options</div>
                        @php($failedStages = collect($job->progress['stages'] ?? [])->filter(fn ($s) => ($s['status'] ?? '') === 'failed')->keys())
                        @foreach($failedStages as $failedStage)
                            <form method="POST" action="{{ route('ai.videos.retry', [$job, $failedStage]) }}" style="margin-bottom: 8px;">
                                @csrf
                                <button type="submit" class="btn btn-secondary" style="width: 100%;">↻ Retry {{ ucfirst($failedStage) }}</button>
                            </form>
                        @endforeach
                        <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 8px;">
                            Retrying resumes from the failed stage — already-generated assets are reused.
                        </div>
                    </div>
                </div>
            @else
                <div class="card" style="margin-bottom: 18px;">
                    <div class="card-body" style="padding: 20px; text-align: center;">
                        <div id="aiCurrentStage" style="font-size: 0.9rem; font-weight: 700; margin-bottom: 6px;">{{ $job->stage_label ?: 'Queued…' }}</div>
                        <div style="font-size: 0.78rem; color: var(--text-dim); margin-bottom: 14px;">
                            @if($job->status === 'queued')Waiting for the next scheduler tick…@else Working in the background — this page refreshes automatically.@endif
                        </div>
                        <div style="width: 100%; height: 6px; border-radius: 999px; background: rgba(255,255,255,0.06); overflow: hidden;">
                            <div id="aiProgressBar" style="height: 100%; width: 0%; background: linear-gradient(90deg, #3b82f6, #06b6d4); border-radius: 999px; transition: width 0.8s ease;"></div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-body" style="padding: 16px;">
                    <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 10px;">Details</div>
                    <div style="display: flex; flex-direction: column; gap: 7px; font-size: 0.8rem;">
                        <div style="display: flex; justify-content: space-between;"><span style="color: var(--text-dim);">Title</span><span style="text-align: right; max-width: 65%;">{{ $job->title ?: '—' }}</span></div>
                        <div style="display: flex; justify-content: space-between;"><span style="color: var(--text-dim);">Background</span><span>{{ $job->background_duration ? number_format($job->background_duration, 1).'s' : '—' }}{{ $job->background_width ? ' · '.$job->background_width.'x'.$job->background_height : '' }}</span></div>
                        <div style="display: flex; justify-content: space-between;"><span style="color: var(--text-dim);">Narration</span><span>{{ $job->voice ? number_format($job->voice['duration'] ?? 0, 1).'s' : '—' }}</span></div>
                        <div style="display: flex; justify-content: space-between;"><span style="color: var(--text-dim);">Audio mode</span><span>{{ ucfirst($job->progress['audio_mode'] ?? 'mute') }}</span></div>
                        <div style="display: flex; justify-content: space-between;"><span style="color: var(--text-dim);">Created</span><span>{{ $job->created_at->format('M d, h:i A') }}</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/ai.js') }}"></script>
@endpush
