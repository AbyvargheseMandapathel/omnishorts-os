{{-- Daily Auto-Generation — hands-free: topic → script → images → voice → render, once per day. --}}
<div class="card" style="margin-bottom: 22px;">
    <div class="card-body" style="padding: 20px 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--accent-emerald); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; font-size: 1rem;">Daily Auto-Generation</div>
                <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                    Every day at the set time: pick the next topic, generate the script, images, voice, and render the final MP4 — automatically. Uses the providers assigned to the content type.
                </div>
            </div>
            @if($aiDaily['last_run'])
                <span class="badge" style="background: rgba(16, 185, 129, 0.12); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); white-space: nowrap;">Last run: {{ $aiDaily['last_run'] }}</span>
            @endif
        </div>

        @if($aiDaily['last_error'])
            <div class="alert alert-error" style="margin-bottom: 14px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" x2="12" y1="8" y2="12"></line><line x1="12" x2="12.01" y1="16" y2="16"></line></svg>
                    <span>Last attempt did not generate: {{ $aiDaily['last_error'] }}</span>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.ai.daily.save') }}">
            @csrf
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                <div class="form-group">
                    <label class="form-label">Enabled</label>
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer;">
                        <input type="checkbox" name="enabled" value="1" style="accent-color: var(--primary);" {{ $aiDaily['enabled'] ? 'checked' : '' }}>
                        Generate automatically
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">Time of day</label>
                    <input type="time" name="time" class="form-input" value="{{ $aiDaily['time'] }}" style="max-width: 150px;">
                </div>
                <div class="form-group">
                    <label class="form-label">Content type</label>
                    <select name="content_type" class="form-select">
                        @foreach($aiContentTypes as $contentType)
                            <option value="{{ $contentType }}" {{ $aiDaily['content_type'] === $contentType ? 'selected' : '' }}>{{ ucfirst($contentType) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Topic pool <span style="color: var(--text-dim); font-weight: 400;">(one per line — rotated one per day)</span></label>
                <textarea name="topics" class="form-textarea" rows="4" style="font-family: ui-monospace, monospace; font-size: 0.78rem;" placeholder="The strange intelligence of octopuses&#10;How bees communicate through dance&#10;Why the sky is blue">{{ $aiDaily['topics'] }}</textarea>
                <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                    Empty list = the text AI proposes today's topic automatically.
                </span>
            </div>

            <div class="form-group" style="margin-bottom: 14px;">
                <label class="form-label">Background video path <span style="color: var(--text-dim); font-weight: 400;">(on the video disk, optional)</span></label>
                <input type="text" name="background_path" class="form-input" style="max-width: 480px;" placeholder="e.g. ai_backgrounds/my-bg.mp4" value="{{ $aiDaily['background_path'] }}">
                <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                    Leave blank to auto-generate a black 720×1280 background.
                </span>
            </div>

            <div class="save-row">
                <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer; margin-right: 16px;">
                    <input type="checkbox" name="auto_approve" value="1" style="accent-color: var(--primary);" {{ $aiDaily['auto_approve'] ? 'checked' : '' }}>
                    Auto-approve finished videos into the Content Library
                </label>
                <button type="submit" class="btn btn-primary btn-sm">Save Daily Settings</button>
            </div>
        </form>
    </div>
</div>
