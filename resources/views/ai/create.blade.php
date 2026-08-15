@extends('layouts.app', ['title' => 'Create AI Video'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Create AI Video</h1>
        <p>Upload a background video you have rights to, describe the topic, and the pipeline writes the script, generates scene images + voice + captions, and renders the final MP4.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('ai.videos.index') }}" class="btn btn-secondary">
            <span>AI Video Jobs</span>
        </a>
    </div>
</div>

@if($errors->has('ai_config'))
    <div class="alert alert-error" style="margin-bottom: 18px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" x2="12" y1="8" y2="12"></line><line x1="12" x2="12.01" y1="16" y2="16"></line></svg>
            <span>{{ $errors->first('ai_config') }}</span>
        </div>
    </div>
@endif

<div style="display: grid; grid-template-columns: 1fr 340px; gap: 22px; align-items: start;">
    <div>
        <div class="card">
            <div class="card-body" style="padding: 20px 24px;">
                <form method="POST" action="{{ route('ai.videos.store') }}" enctype="multipart/form-data" id="aiVideoForm">
                    @csrf

                    <div class="form-group">
                        <label class="form-label">Background Video</label>
                        <input type="file" name="video_file" id="aiBackgroundFile" class="form-input" accept="video/mp4,video/quicktime,video/x-msvideo,video/webm" required>
                        <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                            MP4 / MOV / AVI / WEBM, up to 100 MB. You must have the rights to use it — it becomes the base layer of the finished video.
                        </span>
                        @error('video_file')<span style="color: #f87171; font-size: 0.78rem;">{{ $message }}</span>@enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label">Topic / Instructions</label>
                        <textarea name="topic" class="form-textarea" rows="3" placeholder="e.g. The strange intelligence of octopuses — why they have three hearts and nine brains" required>{{ old('topic') }}</textarea>
                        @error('topic')<span style="color: #f87171; font-size: 0.78rem;">{{ $message }}</span>@enderror
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
                        <div class="form-group">
                            <label class="form-label">Content Type</label>
                            <select name="content_type" id="aiContentType" class="form-select" data-provider-summary>
                                @foreach($contentTypes as $type)
                                    <option value="{{ $type }}" {{ old('content_type', 'video') === $type ? 'selected' : '' }}>{{ ucfirst($type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Scenes</label>
                            <select name="scenes_count" class="form-select">
                                @foreach($sceneOptions as $count)
                                    <option value="{{ $count }}" {{ (int) old('scenes_count', $defaultScenes) === $count ? 'selected' : '' }}>{{ $count }} scenes</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Language</label>
                            <select name="language" class="form-select">
                                @foreach($languages as $lang)
                                    <option value="{{ $lang }}" {{ old('language', 'en') === $lang ? 'selected' : '' }}>{{ strtoupper($lang) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-group">
                            <label class="form-label">Tone</label>
                            <select name="tone" class="form-select">
                                @foreach($tones as $tone)
                                    <option value="{{ $tone }}" {{ old('tone', 'engaging') === $tone ? 'selected' : '' }}>{{ ucfirst($tone) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Target Audience <span style="color: var(--text-dim); font-weight: 400;">(optional)</span></label>
                            <input type="text" name="audience" class="form-input" placeholder="e.g. curious teens, busy professionals" value="{{ old('audience') }}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Background Audio <span style="color: var(--text-dim); font-weight: 400;">(narration is always the primary audio)</span></label>
                        <select name="audio_mode" class="form-select" style="max-width: 300px;">
                            <option value="mute" {{ old('audio_mode', 'mute') === 'mute' ? 'selected' : '' }}>Mute original audio</option>
                            <option value="keep" {{ old('audio_mode') === 'keep' ? 'selected' : '' }}>Keep — ducked under the narration</option>
                            <option value="reduce" {{ old('audio_mode') === 'reduce' ? 'selected' : '' }}>Reduce volume</option>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div class="form-group">
                            <label class="form-label">Title Override <span style="color: var(--text-dim); font-weight: 400;">(optional)</span></label>
                            <input type="text" name="title" class="form-input" placeholder="Defaults to the AI title" value="{{ old('title') }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description <span style="color: var(--text-dim); font-weight: 400;">(optional)</span></label>
                            <input type="text" name="description" class="form-input" placeholder="Defaults to the AI description" value="{{ old('description') }}">
                        </div>
                    </div>

                    <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border-subtle);">
                        <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; font-size: 0.85rem; color: var(--text-main);">
                            <input type="checkbox" name="rights_confirmed" value="1" required style="accent-color: var(--primary); margin-top: 3px;">
                            <span>
                                <strong>I confirm that I have the necessary rights or permission to use this uploaded video.</strong>
                                <span style="display: block; font-size: 0.78rem; color: var(--text-dim); margin-top: 2px;">Don't use content you don't own. The generated scenes, voice, and captions are created by the AI pipeline on top of your background.</span>
                            </span>
                        </label>
                    </div>

                    <div class="save-row" style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary" id="aiGenerateBtn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                            <span>Generate Video</span>
                        </button>
                        <span style="font-size: 0.76rem; color: var(--text-dim);" id="aiSubmitHint">Generation runs in the background — you can leave this page.</span>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="aiProviderCard" data-summary="{{ e(json_encode($contentTypeConfig)) }}">
        <div class="card">
            <div class="card-body" style="padding: 18px 20px;">
                <div style="font-weight: 700; font-size: 0.9rem; margin-bottom: 2px;">AI Providers</div>
                <div style="font-size: 0.76rem; color: var(--text-dim); margin-bottom: 14px;">Resolved automatically from Settings — no keys needed here.</div>
                <div style="display: flex; flex-direction: column; gap: 12px;" id="aiProviderList">
                    @php($selected = old('content_type', 'video'))
                    @foreach(['text' => 'Text AI', 'image' => 'Image AI', 'voice' => 'Voice AI'] as $kind => $label)
                        <div>
                            <div style="font-size: 0.72rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase; margin-bottom: 5px;">{{ $label }}</div>
                            @php($chain = $contentTypeConfig[$selected][$kind] ?? [])
                            @if($chain === [])
                                <div style="font-size: 0.78rem; color: #f87171;">Not configured — set it up in Settings first.</div>
                            @else
                                @foreach($chain as $i => $conn)
                                    <div style="font-size: 0.8rem; padding: 5px 9px; border-radius: 7px; background: rgba(255,255,255,0.04); border: 1px solid var(--border-subtle); margin-bottom: 5px;">
                                        <span style="font-weight: 600;">{{ $conn['name'] }}</span>
                                        <span style="color: var(--text-dim); font-size: 0.72rem;">{{ ucfirst(str_replace('_', ' ', $conn['provider'])) }}@if($conn['model']) · {{ $conn['model'] }}@endif</span>
                                        @if($i === 0 && count($chain) > 1)<span style="color: var(--accent-emerald); font-size: 0.7rem; margin-left: 6px;">primary</span>@elseif($i > 0)<span style="color: var(--text-dim); font-size: 0.7rem; margin-left: 6px;">fallback</span>@endif
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                </div>
                <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border-subtle);">
                    <a href="{{ route('settings.index') }}" style="font-size: 0.8rem; color: var(--primary); font-weight: 600; text-decoration: none;">⚙ Manage AI Connections & Content Types</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/ai.js') }}"></script>
@endpush
