{{-- AI Connections — centralized, per-user provider credentials. --}}
<div class="card" style="margin-bottom: 22px;">
    <div class="card-body" style="padding: 20px 24px;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border-subtle); color: var(--accent-emerald); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a4 4 0 0 1 4 4c0 .6-.13 1.17-.36 1.68A4 4 0 0 1 20 11c0 .4-.05.78-.15 1.15A4 4 0 0 1 21 16a4 4 0 0 1-4 4c-.5 0-.98-.09-1.43-.25A4 4 0 0 1 12 22a4 4 0 0 1-3.57-2.25A4 4 0 0 1 7 20a4 4 0 0 1-4-4c0-.4.05-.78.15-1.15A4 4 0 0 1 3 11c0-.85.27-1.63.72-2.27A4 4 0 0 1 8 6c.6 0 1.17.13 1.68.36A4 4 0 0 1 12 2Z"></path><path d="M9 12h6"></path><path d="M12 9v6"></path></svg>
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 700; font-size: 1rem;">AI Connections</div>
                <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 2px;">
                    Configure each AI provider once — then assign connections to content types and reuse them across every AI video.
                </div>
            </div>
        </div>

        {{-- Existing connections --}}
        @forelse($aiConnections as $connection)
            <details class="ai-connection-row" style="margin-bottom: 10px;" data-connection-id="{{ $connection->id }}">
                <summary style="list-style: none; cursor: pointer; padding: 12px 14px; border: 1px solid var(--border-subtle); border-radius: 10px; background: rgba(255,255,255,0.03); display: flex; align-items: center; gap: 12px;">
                    <span style="flex: 1; min-width: 0;">
                        <span style="font-weight: 700; font-size: 0.9rem;">{{ $connection->name }}</span>
                        <span style="display: block; font-size: 0.75rem; color: var(--text-dim); margin-top: 2px;">
                            {{ ucfirst($connection->type) }} · {{ ucfirst(str_replace('_', ' ', $connection->provider)) }}
                            @if($connection->effectiveModel()) · {{ $connection->effectiveModel() }}@endif
                            · {{ $connection->assignedContentTypes() === [] ? 'not assigned to any content type' : 'for: '.implode(', ', array_map('ucfirst', $connection->assignedContentTypes())) }}
                        </span>
                    </span>
                    <span class="badge" style="{{ $connection->is_active ? 'background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3);' : 'background: rgba(255,255,255,0.06); color: var(--text-dim); border: 1px solid rgba(255,255,255,0.14);' }}">
                        {{ $connection->is_active ? '● Active' : '○ Inactive' }}
                    </span>
                    <span style="font-size: 0.78rem; color: var(--text-dim);">Edit ▾</span>
                </summary>
                <div style="padding: 14px 14px 4px;">
                    <form method="POST" action="{{ route('settings.ai.connections.save') }}">
                        @csrf
                        <input type="hidden" name="id" value="{{ $connection->id }}">
                        @include('settings.partials.ai-connection-form', [
                            'connection' => $connection,
                            'selectedTypes' => $connection->assignedContentTypes(),
                        ])
                    </form>
                    <div style="padding: 8px 0 14px;">
                        <form method="POST" action="{{ route('settings.ai.connections.delete', $connection) }}" data-confirm="Delete connection '{{ $connection->name }}'? Content-type configs that use it will be cleared.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Delete Connection</button>
                        </form>
                    </div>
                </div>
            </details>
        @empty
            <div style="font-size: 0.8rem; color: var(--text-muted); padding: 8px 2px 14px;">
                No AI connections yet — add your first one below.
            </div>
        @endforelse

        {{-- New connection --}}
        <details style="margin-bottom: 4px;">
            <summary style="list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; color: var(--primary); font-weight: 600; font-size: 0.85rem; padding: 6px 0;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"></line><line x1="5" x2="19" y1="12" y2="12"></line></svg>
                Add New Connection
            </summary>
            <div style="padding: 12px 2px 4px;">
                <form method="POST" action="{{ route('settings.ai.connections.save') }}">
                    @csrf
                    @include('settings.partials.ai-connection-form', [
                        'connection' => null,
                        'selectedTypes' => [],
                    ])
                </form>
            </div>
        </details>
    </div>
</div>
