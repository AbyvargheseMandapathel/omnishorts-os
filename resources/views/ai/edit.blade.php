@extends('layouts.app', ['title' => 'Edit AI Video #'.$job->id])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Edit AI Video #{{ $job->id }}</h1>
        <p>Adjust the title, narration, and image prompts, then save — voice, captions, and rendering regenerate automatically. Already-generated images are reused unless their prompt changes.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('ai.videos.show', $job) }}" class="btn btn-secondary"><span>← Back</span></a>
    </div>
</div>

@php($script = $job->script ?? [])

<div class="card">
    <div class="card-body" style="padding: 20px 24px;">
        <form method="POST" action="{{ route('ai.videos.update', $job) }}">
            @csrf
            @method('PUT')

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 14px;">
                <div class="form-group">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-input" value="{{ $job->title ?: ($script['title'] ?? '') }}" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Background Audio</label>
                    <select name="audio_mode" class="form-select">
                        <option value="mute" {{ ($job->progress['audio_mode'] ?? 'mute') === 'mute' ? 'selected' : '' }}>Mute original audio</option>
                        <option value="keep" {{ ($job->progress['audio_mode'] ?? '') === 'keep' ? 'selected' : '' }}>Keep — ducked under narration</option>
                        <option value="reduce" {{ ($job->progress['audio_mode'] ?? '') === 'reduce' ? 'selected' : '' }}>Reduce volume</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-textarea" rows="2">{{ $job->description ?: ($script['description'] ?? '') }}</textarea>
            </div>

            <div style="font-weight: 700; font-size: 0.95rem; margin: 22px 0 4px;">Scenes</div>
            <div style="font-size: 0.78rem; color: var(--text-dim); margin-bottom: 14px;">
                Edits below re-run the voice (from the scene narrations) and re-render. Regenerate Image re-does only that scene.
            </div>

            <div style="display: flex; flex-direction: column; gap: 16px;">
                @foreach($job->scenes ?? [] as $index => $scene)
                    <div style="border: 1px solid var(--border-subtle); border-radius: 12px; padding: 16px;">
                        <div style="display: flex; gap: 16px; align-items: flex-start;">
                            <div style="width: 110px; flex-shrink: 0; text-align: center;">
                                <div style="aspect-ratio: 9/16; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-subtle); background: rgba(255,255,255,0.03); display: flex; align-items: center; justify-content: center;">
                                    @if(($scene['image_status'] ?? '') === 'done' && filled($scene['image_path'] ?? null))
                                        <img src="{{ route('ai.videos.image', [$job, $scene['scene_number']]) }}" alt="Scene {{ $scene['scene_number'] }}" style="width: 100%; height: 100%; object-fit: cover;">
                                    @else
                                        <span style="font-size: 0.65rem; color: {{ ($scene['image_status'] ?? '') === 'failed' ? '#f87171' : 'var(--text-dim)' }}; padding: 6px;">
                                            {{ ($scene['image_status'] ?? '') === 'failed' ? 'Failed — regenerate' : (($scene['image_status'] ?? '') === 'running' ? 'Generating…' : 'Pending') }}
                                        </span>
                                    @endif
                                </div>
                                <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 6px;">
                                    @if(isset($scene['start_time'])) {{ number_format($scene['start_time'], 1) }}s–{{ number_format($scene['end_time'], 1) }}s @endif
                                </div>
                                <form method="POST" action="{{ route('ai.videos.scenes.regenerate', [$job, $scene['scene_number']]) }}" style="margin-top: 8px;">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; font-size: 0.72rem;">↻ Regenerate Image</button>
                                </form>
                            </div>

                            <div style="flex: 1; display: flex; flex-direction: column; gap: 12px;">
                                <input type="hidden" name="scenes[{{ $index }}][scene_number]" value="{{ $scene['scene_number'] }}">
                                <div>
                                    <label class="form-label" style="font-size: 0.78rem;">Narration — Scene {{ $scene['scene_number'] }}</label>
                                    <textarea name="scenes[{{ $index }}][narration]" class="form-textarea" rows="2">{{ $scene['narration'] }}</textarea>
                                </div>
                                <div>
                                    <label class="form-label" style="font-size: 0.78rem;">Image Prompt — Scene {{ $scene['scene_number'] }}</label>
                                    <textarea name="scenes[{{ $index }}][image_prompt]" class="form-textarea" rows="3">{{ $scene['image_prompt'] }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Full narration is informational — the voice is rebuilt from the scene narrations. --}}
            <div class="form-group" style="margin-top: 18px;">
                <label class="form-label">Full Narration <span style="color: var(--text-dim); font-weight: 400;">(review only — regenerated from the scene narrations above)</span></label>
                <textarea class="form-textarea" rows="4" disabled style="opacity: 0.7;">{{ $script['narration'] ?? $job->topic }}</textarea>
            </div>

            <div class="save-row" style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary">Save & Regenerate</button>
                <span style="font-size: 0.76rem; color: var(--text-dim);">Saving queues the pipeline from the images stage onward.</span>
            </div>
        </form>
    </div>
</div>
@endsection
