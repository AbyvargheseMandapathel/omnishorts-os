@extends('layouts.app', ['title' => 'Upload Short Video'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Upload Reel</h1>
        <p>Drop your edited vertical video. It's ready to schedule to YouTube immediately.</p>
    </div>
</div>

<div style="max-width: 800px; margin: 0 auto;">
    <div class="card">
        <div class="card-body" style="padding: 32px;">
            <form method="POST" action="{{ route('videos.store') }}" enctype="multipart/form-data" id="uploadForm">
                @csrf

                <!-- Dropzone Area -->
                <div id="dropzone" style="border: 2px dashed rgba(255, 255, 255, 0.28); border-radius: var(--radius-xl); padding: 48px 24px; text-align: center; background: rgba(255, 255, 255, 0.02); cursor: pointer; transition: all var(--transition-fast); margin-bottom: 24px;">
                    <input type="file" name="video_file" id="videoFileInput" accept="video/mp4,video/mov,video/webm" style="display: none;">
                    
                    <div style="width: 64px; height: 64px; border-radius: 18px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" x2="12" y1="3" y2="15"></line></svg>
                    </div>
                    
                    <h3 style="font-size: 1.2rem; font-weight: 700; margin-bottom: 6px;" id="dropzoneTitle">Choose a video file or drag & drop here</h3>
                    <p style="color: var(--text-muted); font-size: 0.88rem; max-width: 440px; margin: 0 auto 12px;">
                        Supports MP4, MOV, or WebM. Vertical 9:16 recommended for YouTube Shorts.
                    </p>
                    <span class="btn btn-secondary btn-sm" id="selectFileBtn">Browse Local Files</span>
                </div>

                <!-- Live Uploading Simulation Progress Bar -->
                <div id="progressSection" style="display: none; margin-bottom: 28px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-weight: 600; font-size: 0.9rem;" id="progressLabel">Transcoding 9:16 Video Asset...</span>
                        <span style="font-weight: 700; font-size: 0.9rem; color: var(--primary);" id="progressPercent">0%</span>
                    </div>
                    <div style="width: 100%; height: 8px; background: rgba(255,255,255,0.1); border-radius: 999px; overflow: hidden;">
                        <div id="progressBar" style="width: 0%; height: 100%; background: #ffffff; transition: width 0.3s ease;"></div>
                    </div>
                    <div style="font-size: 0.78rem; color: var(--text-dim); margin-top: 8px;" id="progressSubLabel">
                        Extracting high-retention audio keywords & transcript
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Video Title / Working Concept</label>
                    <input type="text" name="title" id="videoTitleInput" class="form-input" placeholder="e.g. 5 AI Prompts That Will Save You 20 Hours a Week" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Niche</label>
                    <input type="text" class="form-input" value="{{ $channel->category ?? 'Tech & Creators' }}" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea name="description" class="form-textarea" placeholder="Any context for the auto-generated title and caption."></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                    <a href="{{ route('videos.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" id="submitUploadBtn" class="btn btn-primary btn-lg">
                        Upload Reel →
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/upload.js') }}"></script>
@endpush
