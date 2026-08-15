@extends('layouts.app', ['title' => 'Bulk Upload Reel Pack'])

@section('content')
<div class="page-header">
    <div class="page-title-group">
        <h1>Bulk Import Reels Bundle</h1>
        <p>Drop your whole reel pack. Videos are auto-queued into your channel's daily posting slots and go live by themselves.</p>
    </div>
</div>

<div style="max-width: 860px; margin: 0 auto;">
    <div class="card">
        <div class="card-body" style="padding: 32px;">
            @php
                // The schedule shown reflects the account that will receive the
                // uploads (first connected by default; swaps with the selector).
                $bulkAccount = $youtubeAccounts->first();
                $bulkTimes = $bulkAccount ? $bulkAccount->postingTimes() : $channel->postingTimes();
                $bulkLabel = implode(' & ', array_map(fn ($t) => \Carbon\Carbon::createFromFormat('H:i', $t)->format('h:i A'), $bulkTimes));
            @endphp

            <form method="POST" action="{{ route('videos.bulk.store') }}" enctype="multipart/form-data" id="bulkForm">
                @csrf

                <!-- Multi-File Dropzone -->
                <div id="dropzone" data-schedule-per-day="{{ count($bulkTimes) }}" style="border: 2px dashed rgba(255, 255, 255, 0.28); border-radius: var(--radius-xl); padding: 44px 24px; text-align: center; background: rgba(255, 255, 255, 0.02); cursor: pointer; transition: all var(--transition-fast); margin-bottom: 20px;">
                    <input type="file" name="videos[]" id="videoFilesInput" accept="video/mp4,video/mov,video/webm" multiple style="display: none;">
                    <div style="width: 64px; height: 64px; border-radius: 18px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-subtle); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" x2="12" y1="3" y2="15"></line></svg>
                    </div>
                    <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 6px;" id="dropzoneTitle">Select reel pack files or drag & drop here</h3>
                    <p style="color: var(--text-muted); font-size: 0.88rem; max-width: 460px; margin: 0 auto 12px;">
                        MP4, MOV, or WebM. Pick as many as you want — each becomes a scheduled YouTube Short.
                    </p>
                    <span class="btn btn-secondary btn-sm" id="selectFilesBtn">Browse Reel Pack</span>
                </div>

                <!-- Selected Files List -->
                <div id="fileListSection" style="display: none; margin-bottom: 24px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 16px 20px; max-height: 220px; overflow-y: auto;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                        <span style="font-weight: 700; font-size: 0.9rem;">Selected Reels</span>
                        <span style="font-weight: 700; font-size: 0.9rem; color: var(--primary);" id="fileCount">0 files</span>
                    </div>
                    <ul id="fileList" style="list-style: none; padding: 0; margin: 0; font-size: 0.82rem; color: var(--text-muted);"></ul>
                </div>

                @error('videos')
                    <div style="color: #f87171; font-size: 0.85rem; margin-bottom: 12px;">{{ $message }}</div>
                @enderror

                <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.12); border-radius: var(--radius-lg); padding: 16px 20px; margin-bottom: 18px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                        <div>
                            <div style="font-weight: 700; font-size: 0.9rem; color: var(--text-main); margin-bottom: 3px;" id="autoScheduleLabel">⏰ Auto Schedule: {{ count($bulkTimes) }} post(s)/day at {{ $bulkLabel }}</div>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">Reels are queued into these daily slots in order.</div>
                        </div>
                        <a href="{{ route('accounts.index') }}" class="btn btn-secondary btn-sm">Change Cron</a>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Start Publishing Date</label>
                    <input type="date" name="start_date" id="startDateInput" class="form-input" value="{{ \Carbon\Carbon::tomorrow()->format('Y-m-d') }}" style="max-width: 300px;">
                    <span style="font-size: 0.78rem; color: var(--text-dim); display: block; margin-top: 4px;">First post goes live on this date.</span>
                </div>

                <div class="form-group">
                    <label class="form-label">YouTube Account</label>
                        <select name="youtube_account_id" class="form-select" id="youtubeAccountSelect">
                            @forelse($youtubeAccounts as $acc)
                                <option value="{{ $acc->id }}"
                                    data-posts-per-day="{{ count($acc->postingTimes()) }}"
                                    data-times-label="{{ implode(' & ', array_map(fn ($t) => \Carbon\Carbon::createFromFormat('H:i', $t)->format('h:i A'), $acc->postingTimes())) }}">{{ $acc->account_name }} ({{ $acc->handle }})</option>
                            @empty
                                <option value="">No YouTube account connected</option>
                            @endforelse
                        </select>
                        @if($youtubeAccounts->isEmpty())
                            <span style="font-size: 0.78rem; color: #f87171; display: block; margin-top: 4px;">
                                <a href="{{ route('accounts.index') }}" style="color: var(--primary); text-decoration: underline;">Connect a YouTube account</a> before scheduling.
                            </span>
                        @endif
                        @error('youtube_account_id')
                            <span style="font-size: 0.78rem; color: #f87171; display: block; margin-top: 4px;">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <!-- Schedule Preview -->
                <div style="background: rgba(16, 185, 129, 0.06); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: var(--radius-lg); padding: 16px 20px; margin-top: 16px;">
                    <div style="font-weight: 700; font-size: 0.9rem; color: var(--accent-emerald); margin-bottom: 6px;">⚡ Auto-Schedule Preview</div>
                    <div style="font-size: 0.85rem; color: var(--text-muted);" id="schedulePreview">
                        Select files to see how your pack spreads across the calendar.
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                    <a href="{{ route('videos.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-lg" @if($youtubeAccounts->isEmpty()) disabled @endif>
                        Queue Bundle to Calendar 🚀
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/bulk.js') }}"></script>
@endpush
