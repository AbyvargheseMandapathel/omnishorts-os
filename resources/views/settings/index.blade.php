@extends('layouts.app', ['title' => 'Settings'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Settings</h1>
        <p>Configure the AI engine that generates hooks, titles, and descriptions for scheduled uploads.</p>
    </div>
</div>

<div style="max-width: 720px;">
    <div class="card" style="margin-bottom: 22px;">
        <div class="card-body" style="padding: 20px 24px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--accent-cyan); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a4 4 0 0 1 4 4c0 .6-.13 1.17-.36 1.68A4 4 0 0 1 20 11c0 .4-.05.78-.15 1.15A4 4 0 0 1 21 16a4 4 0 0 1-4 4c-.5 0-.98-.09-1.43-.25A4 4 0 0 1 12 22a4 4 0 0 1-3.57-2.25A4 4 0 0 1 7 20a4 4 0 0 1-4-4c0-.4.05-.78.15-1.15A4 4 0 0 1 3 11c0-.85.27-1.63.72-2.27A4 4 0 0 1 8 6c.6 0 1.17.13 1.68.36A4 4 0 0 1 12 2Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 700; font-size: 1rem;">Gemini AI</div>
                    <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                        Video analysis for scheduled uploads — generates hook, title, description, hashtags, thumbnail text, and more.
                    </div>
                </div>
                <span class="badge" style="{{ $geminiEnabled ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);' }}">
                    {{ $geminiEnabled ? '● Enabled' : '○ Disabled' }}
                </span>
            </div>

            <form method="POST" action="{{ route('settings.gemini.save') }}">
                @csrf

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                        <input type="checkbox" name="enabled" value="1" style="accent-color: var(--primary);" {{ $geminiEnabled ? 'checked' : '' }}>
                        Enable Gemini AI Analysis
                    </label>
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                        When ON, the cron analyzes each video with Gemini right before its scheduled YouTube upload. When OFF, uploads use the existing metadata.
                    </span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="gemini_model">Gemini Model</label>
                    <input type="text" name="model" id="gemini_model" class="form-input" value="{{ $geminiModel }}" placeholder="e.g. gemini-1.5-flash" list="geminiModelsList" style="max-width: 360px;" required>
                    <datalist id="geminiModelsList">
                        @foreach($geminiModels as $model)
                            <option value="{{ $model }}"></option>
                        @endforeach
                    </datalist>
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                        Type any Gemini model name, e.g. <code style="font-size: 0.72rem;">gemini-1.5-flash</code>, <code style="font-size: 0.72rem;">gemini-2.5-flash</code>, or a preview model.
                    </span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="gemini_api_key">Gemini API Key</label>
                    <input type="password" id="gemini_api_key" name="api_key" class="form-input" placeholder="{{ $geminiHasApiKey ? '••• saved •••' : 'AIza…' }}" autocomplete="new-password" style="max-width: 420px;">
                    @if($geminiHasApiKey)
                        <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 0.78rem; color: var(--text-dim); cursor: pointer;">
                            <input type="checkbox" name="remove_api_key" value="1" style="accent-color: var(--primary);">
                            Remove saved API key
                        </label>
                    @endif
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
                        Stored encrypted server-side. Never shown again — leave blank to keep the saved one.
                    </span>
                </div>

                <div style="display: flex; align-items: center; gap: 12px; margin-top: 16px;">
                    <button type="submit" class="btn btn-primary btn-sm">Save Settings</button>
                    <button type="button" class="btn btn-secondary btn-sm" id="testGeminiBtn" data-test-url="{{ route('settings.gemini.test') }}" @if(!$geminiHasApiKey) disabled title="Save an API key first" @endif>Test Gemini Connection</button>
                    <span id="geminiTestResult" style="font-size: 0.8rem;"></span>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/settings.js') }}"></script>
@endpush
