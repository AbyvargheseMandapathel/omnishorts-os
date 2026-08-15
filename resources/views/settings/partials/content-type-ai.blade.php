{{-- Content Type AI — primary/fallback provider per content type. --}}
<div class="card" style="margin-bottom: 22px;">
    <div class="card-body" style="padding: 20px 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--accent-cyan); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M3 9h18"></path><path d="M9 21V9"></path></svg>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; font-size: 1rem;">Content Type AI</div>
                <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                    Pick the primary (and optional fallback) AI for each content type. Only connections assigned to that content type are listed.
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('settings.ai.content-types.save') }}">
            @csrf
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem; min-width: 640px;">
                    <thead>
                        <tr style="color: var(--text-dim); text-align: left;">
                            <th style="padding: 8px 10px; border-bottom: 1px solid var(--border-subtle);">Content Type</th>
                            <th style="padding: 8px 10px; border-bottom: 1px solid var(--border-subtle);">Text AI</th>
                            <th style="padding: 8px 10px; border-bottom: 1px solid var(--border-subtle);">Image AI</th>
                            <th style="padding: 8px 10px; border-bottom: 1px solid var(--border-subtle);">Voice AI</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($aiContentTypes as $contentType)
                            @php
                                $assigned = $aiConnections->filter(fn ($c) => $c->isAssignedTo($contentType));
                            @endphp
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-subtle); font-weight: 700; white-space: nowrap;">{{ ucfirst($contentType) }}</td>

                                @foreach(['text', 'image', 'voice'] as $kind)
                                    <td style="padding: 10px; border-bottom: 1px solid var(--border-subtle); min-width: 170px;">
                                        @php($candidates = $assigned->where('type', $kind)->values())
                                        @if($candidates->isEmpty())
                                            <span style="color: var(--text-muted); font-size: 0.75rem;">—</span>
                                        @else
                                            <div style="display: flex; flex-direction: column; gap: 6px;">
                                                <select name="configs[{{ $contentType }}][{{ $kind }}_primary]" class="form-select" style="padding: 5px 8px; font-size: 0.76rem;">
                                                    <option value="">—</option>
                                                    @foreach($candidates as $candidate)
                                                        <option value="{{ $candidate->id }}" {{ optional($aiContentTypeConfigs[$contentType.':'.$kind.'_primary'] ?? null)->ai_connection_id === $candidate->id ? 'selected' : '' }}>{{ $candidate->name }}</option>
                                                    @endforeach
                                                </select>
                                                <select name="configs[{{ $contentType }}][{{ $kind }}_fallback]" class="form-select" style="padding: 5px 8px; font-size: 0.76rem; opacity: 0.85;">
                                                    <option value="">No fallback</option>
                                                    @foreach($candidates as $candidate)
                                                        <option value="{{ $candidate->id }}" {{ optional($aiContentTypeConfigs[$contentType.':'.$kind.'_fallback'] ?? null)->ai_connection_id === $candidate->id ? 'selected' : '' }}>{{ $candidate->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="save-row" style="margin-top: 14px;">
                <button type="submit" class="btn btn-primary btn-sm">Save Content Type AI</button>
                <span style="font-size: 0.76rem; color: var(--text-dim);">
                    Fallbacks are tried automatically on timeouts / rate limits / provider errors.
                </span>
            </div>
        </form>
    </div>
</div>
