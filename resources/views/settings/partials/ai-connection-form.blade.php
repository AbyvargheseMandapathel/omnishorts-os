@php($isEdit = $connection !== null)
@php($typeOptions = ['text' => 'Text', 'image' => 'Image', 'voice' => 'Voice'])
@php($currentType = $isEdit ? $connection->type : old('type', 'text'))

<div class="form-group">
    <label class="form-label">Connection Name</label>
    <input type="text" name="name" class="form-input" style="max-width: 360px;" placeholder="e.g. Groq Main" value="{{ $isEdit ? $connection->name : old('name') }}" required>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
    <div class="form-group">
        <label class="form-label">Type</label>
        <select name="type" class="form-select ai-provider-type" data-provider-select="{{ $isEdit ? $connection->id : 'new' }}">
            @foreach($typeOptions as $value => $label)
                <option value="{{ $value }}" {{ $currentType === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group">
        <label class="form-label">Provider</label>
        <select name="provider" class="form-select ai-provider-option" data-provider-select="{{ $isEdit ? $connection->id : 'new' }}" data-default="{{ $isEdit ? $connection->provider : '' }}">
            @foreach($aiProviders as $key => $entry)
                <option value="{{ $key }}" data-type="{{ $entry['type'] }}" {{ $isEdit && $connection->provider === $key ? 'selected' : '' }}>
                    {{ ucfirst(str_replace('_', ' ', $key)) }}
                </option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label class="form-label">API Key</label>
    <input type="password" name="api_key" class="form-input" style="max-width: 420px;" autocomplete="new-password"
           placeholder="{{ $isEdit && $connection->hasApiKey() ? '••• saved — blank keeps it •••' : 'sk-… / gsk_… / AIza…' }}">
    @if($isEdit && $connection->hasApiKey())
        <label style="display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 0.78rem; color: var(--text-dim); cursor: pointer;">
            <input type="checkbox" name="remove_api_key" value="1" style="accent-color: var(--primary);">
            Remove saved API key
        </label>
    @endif
    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
        Stored encrypted with your app key. Never shown again or sent to the browser.
    </span>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
    <div class="form-group">
        <label class="form-label">Model</label>
        <input type="text" name="model" class="form-input" style="max-width: 100%;" placeholder="e.g. {{ $aiDefaultConfigs[$isEdit ? $connection->provider : 'groq']['model'] ?? 'llama-3.3-70b-versatile' }}" value="{{ $isEdit ? $connection->model : old('model') }}">
    </div>
    <div class="form-group">
        <label class="form-label">Base URL <span style="color: var(--text-dim); font-weight: 400;">(optional)</span></label>
        <input type="url" name="base_url" class="form-input" style="max-width: 100%;" placeholder="OpenAI-compatible endpoints" value="{{ $isEdit ? $connection->base_url : old('base_url') }}">
    </div>
</div>

<div class="form-group">
    <label class="form-label">Additional Configuration <span style="color: var(--text-dim); font-weight: 400;">(JSON, optional)</span></label>
    <input type="text" name="config" class="form-input" style="font-family: ui-monospace, monospace; font-size: 0.78rem;" placeholder='e.g. {"voice":"hi-IN-MadhurNeural","temperature":0.8,"negative_prompt":"blurry, low quality"}' value="{{ $isEdit && $connection->config ? json_encode($connection->config, JSON_UNESCAPED_SLASHES) : old('config') }}">
    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
        Voice providers read <code style="font-size: 0.72rem;">voice</code>; image providers read <code style="font-size: 0.72rem;">negative_prompt</code>; text providers read <code style="font-size: 0.72rem;">temperature</code>.
    </span>
</div>

<div class="form-group">
    <label class="form-label">Available for Content Types</label>
    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
        @foreach($aiContentTypes as $contentType)
                            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer; background: rgba(255,255,255,0.04); border: 1px solid var(--border-subtle); padding: 6px 10px; border-radius: 8px;">
                                <input type="checkbox" name="content_types[]" value="{{ $contentType }}" style="accent-color: var(--primary);" {{ in_array($contentType, $selectedTypes, true) ? 'checked' : '' }}>
                                {{ ucfirst($contentType) }}
                            </label>
        @endforeach
    </div>
    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">
        A connection can power any number of content types — the key is never duplicated.
    </span>
</div>

<div class="save-row" style="margin-top: 10px;">
    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; cursor: pointer; margin-right: 16px;">
        <input type="checkbox" name="is_active" value="1" style="accent-color: var(--primary);" {{ ! $isEdit || $connection->is_active ? 'checked' : '' }}>
        Active
    </label>
    <button type="button" class="btn btn-secondary btn-sm" data-ai-test-connection title="Runs a tiny real request against this provider — nothing is saved">Test Connection</button>
    <span data-ai-test-result style="font-size: 0.8rem; margin-left: 10px;"></span>
    <button type="submit" class="btn btn-primary btn-sm">{{ $isEdit ? 'Save Connection' : 'Add Connection' }}</button>
</div>
