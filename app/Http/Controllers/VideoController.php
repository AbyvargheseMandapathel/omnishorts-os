<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Models\PublicationMetric;
use App\Models\Video;
use App\Services\GeminiVideoAnalyzer;
use App\Services\VideoProbe;
use App\Services\YouTubeAnalytics;
use App\Services\YouTubeUploader;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class VideoController extends Controller
{
    public function index(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $query = $channel->videos()->with(['publications.socialAccount']);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $videos = $query->latest()->paginate(12)->withQueryString();

        return view('videos.index', compact('channel', 'videos'));
    }

    public function create()
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        return view('videos.upload', compact('channel'));
    }

    public function store(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'video_file' => ['nullable', 'file', 'mimes:mp4,mov,avi,webm', 'max:102400'],
        ]);

        $filePath = null;
        $duration = null;
        if ($request->hasFile('video_file')) {
            $writeError = null;
            try {
                // Returns false when the disk write fails (throw=false on the
                // ftp/local disks) but can also THROW (e.g. local adapter
                // construction) — catch both instead of silently creating a
                // video with no file, which is how "my video wasn't saved"
                // happens on a misconfigured disk.
                $filePath = $request->file('video_file')->store('videos', $this->videoDisk());
            } catch (Throwable $e) {
                $filePath = false;
                $writeError = $e->getMessage();
            }

            if ($filePath === false) {
                $disk = $this->videoDisk();
                $error = "Could not save the video file to the \"{$disk}\" disk"
                    .($writeError !== null ? " ({$writeError})" : ' — the write failed silently')
                    .'. Check the disk configuration and permissions (the /health endpoint probes storage and the video disk).';

                if ($request->wantsJson()) {
                    return response()->json(['success' => false, 'error' => $error], 422);
                }

                return back()->withErrors(['video_file' => $error])->withInput();
            }

            $duration = app(VideoProbe::class)->durationSeconds($request->file('video_file')->getRealPath());
        }

        $title = $validated['title'];

        $video = $channel->videos()->create([
            'title' => $title,
            'description' => $validated['description'] ?? null,
            'file_path' => $filePath,
            'duration' => $duration,
            'status' => 'ready',
            // No fabricated metrics: virality score only appears after a real
            // Gemini analysis (or is never shown).
            'ai_data' => [
                'hook' => "Nobody is talking about this $title secret...",
                'caption' => "Here is the ultimate breakdown of {$title}. Tap follow for daily actionable insights!",
                'hashtags' => '#shorts #reels #viral #growth #'.strtolower(str_replace(' ', '', substr($channel->category ?? 'content', 0, 10))),
            ],
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'video' => $video,
                'redirect_url' => route('videos.show', $video),
            ]);
        }

        return redirect()->route('videos.show', $video)->with('success', 'Video uploaded successfully!');
    }

    public function bulkCreate()
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $youtubeAccounts = $channel->socialAccounts()
            ->where('platform', 'youtube')
            ->where('status', 'connected')
            ->get();

        return view('videos.bulk', compact('channel', 'youtubeAccounts'));
    }

    public function bulkStore(Request $request)
    {
        $channel = Auth::user()->currentChannel();
        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $validated = $request->validate([
            'videos' => ['required', 'array', 'min:1', 'max:100'],
            'videos.*' => ['file', 'mimes:mp4,mov,avi,webm', 'max:102400'],
            'start_date' => ['nullable', 'date'],
            'youtube_account_id' => ['nullable', 'exists:social_accounts,id'],
        ]);

        $account = ! empty($validated['youtube_account_id'])
            ? $channel->socialAccounts()->find($validated['youtube_account_id'])
            : $channel->socialAccounts()->where('platform', 'youtube')->where('status', 'connected')->first();

        if (! $account) {
            return back()->withErrors(['youtube_account_id' => 'Connect a YouTube account first before bulk scheduling.'])->withInput();
        }

        $startDate = ! empty($validated['start_date'])
            ? Carbon::parse($validated['start_date'])
            : Carbon::tomorrow();

        $timeSlots = $account->postingTimes();
        $postsPerDay = count($timeSlots);

        $videos = [];
        $failedFiles = [];
        foreach ($request->file('videos') as $file) {
            try {
                // Disk write failures surface as false (throw=false) or as a
                // thrown exception — never let either become a file-less video.
                $filePath = $file->store('videos', $this->videoDisk());
            } catch (Throwable) {
                $filePath = false;
            }

            if ($filePath === false) {
                $failedFiles[] = $file->getClientOriginalName();

                continue;
            }

            $duration = app(VideoProbe::class)->durationSeconds($file->getRealPath());
            $cleanName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $title = Str::title(str_replace(['-', '_'], ' ', $cleanName));

            $videos[] = $channel->videos()->create([
                'title' => $title,
                'description' => null,
                'file_path' => $filePath,
                'duration' => $duration,
                'status' => 'scheduled',
                'ai_data' => [
                    'hook' => "Nobody is talking about this $title secret...",
                    'caption' => "Here is the ultimate breakdown of {$title}. Tap follow for daily actionable insights!",
                    'hashtags' => '#shorts #reels #viral #growth #'.strtolower(str_replace(' ', '', substr($channel->category ?? 'content', 0, 10))),
                ],
            ]);
        }

        if (empty($videos)) {
            $disk = $this->videoDisk();
            $failedList = implode(', ', array_slice($failedFiles, 0, 5)).(count($failedFiles) > 5 ? ' …' : '');

            return back()->withErrors([
                'videos' => "None of the files could be saved to the \"{$disk}\" disk ({$failedList}). ".
                    'The write failed silently — check the disk configuration and permissions (the /health endpoint probes storage and the video disk).',
            ])->withInput();
        }

        $dateCursor = $startDate->copy()->startOfDay();
        foreach ($videos as $index => $video) {
            $dayOffset = intdiv($index, $postsPerDay);
            $slotIndex = $index % $postsPerDay;
            [$hour, $minute] = array_map('intval', explode(':', $timeSlots[$slotIndex]));
            $scheduledAt = $dateCursor->copy()
                ->addDays($dayOffset)
                ->setHour($hour)
                ->setMinute($minute)
                ->setSecond(0);

            Publication::create([
                'video_id' => $video->id,
                'social_account_id' => $account->id,
                'custom_title' => $video->title,
                'custom_caption' => $video->ai_data['caption'] ?? null,
                'custom_hashtags' => $video->ai_data['hashtags'] ?? null,
                'scheduled_at' => $scheduledAt,
                'status' => 'scheduled',
            ]);
        }

        $totalDays = (int) ceil(count($videos) / $postsPerDay);
        $timesLabel = implode(' & ', array_map(fn ($t) => Carbon::createFromFormat('H:i', $t)->format('h:i A'), $timeSlots));

        $message = count($videos).' reels queued to YouTube — '.$postsPerDay.' post(s)/day at '.$timesLabel.' for '.$totalDays.' day(s) starting '.$startDate->format('M d, Y').'.';
        if ($failedFiles !== []) {
            $message .= ' Warning: '.count($failedFiles).' file(s) could not be saved ('
                .implode(', ', array_slice($failedFiles, 0, 3)).(count($failedFiles) > 3 ? ' …' : '')
                .') — they were skipped. Check the video disk (see /health).';
        }

        return redirect()
            ->route('calendar.index', ['date' => $startDate->toDateString()])
            ->with('success', $message);
    }

    public function show(Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            abort(403);
        }

        $video->load(['publications.socialAccount']);
        $youtubeAccounts = $channel->socialAccounts()
            ->where('platform', 'youtube')
            ->where('status', 'connected')
            ->get();

        $nextFreeSlot = $channel->nextFreeSlot();
        $geminiEnabled = app(GeminiVideoAnalyzer::class)->enabled($channel);

        // Public playback URL for the uploaded file (null when no file / disk miss).
        $videoUrl = $video->playbackUrl();

        // Real per-reel stats across the published versions of this video
        // that have a real YouTube watch URL — simulated publishes never
        // count. Never fabricated.
        $realPublications = $video->publications
            ->where('status', 'published')
            ->filter(fn ($p) => filled($p->post_url) && str_contains($p->post_url, 'watch?v='));
        $videoViews = (int) $realPublications->sum(fn ($p) => (int) ($p->analytics['views'] ?? 0));
        $videoLikes = (int) $realPublications->sum(fn ($p) => (int) ($p->analytics['likes'] ?? 0));
        $videoComments = (int) $realPublications->sum(fn ($p) => (int) ($p->analytics['comments'] ?? 0));
        $videoShares = (int) $realPublications->sum(fn ($p) => (int) ($p->analytics['shares'] ?? 0));
        $viewsCurve = PublicationMetric::query()
            ->whereIn('publication_id', $video->publications->pluck('id')->all())
            ->where('fetched_at', '>=', now()->subDays(14))
            ->orderBy('fetched_at')
            ->get()
            ->groupBy(fn ($m) => $m->fetched_at->format('Y-m-d'))
            ->map(fn ($group) => (int) $group->sum('views'))
            ->values()
            ->all();

        // When the reel's stats were last pulled from YouTube (latest metric
        // snapshot) — so a stale Performance card is obvious.
        $lastRefreshedRaw = PublicationMetric::query()
            ->whereIn('publication_id', $video->publications->pluck('id')->all())
            ->max('fetched_at');
        $statsLastRefreshedAt = $lastRefreshedRaw ? Carbon::parse($lastRefreshedRaw) : null;

        return view('videos.show', compact('channel', 'video', 'youtubeAccounts', 'nextFreeSlot', 'geminiEnabled', 'videoUrl', 'videoViews', 'videoLikes', 'videoComments', 'videoShares', 'viewsCurve', 'statsLastRefreshedAt'));
    }

    /**
     * Manually trigger Gemini analysis for a video (same service the cron
     * uses). Failures never touch the existing metadata.
     */
    /**
     * Reupload an already-published reel: creates a fresh scheduled
     * publication so the cron uploads it to YouTube again (new watch URL).
     * The old published publication stays as history.
     */
    public function reupload(Request $request, Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            abort(403);
        }

        $validated = $request->validate([
            'account_id' => ['required', 'exists:social_accounts,id'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $account = $channel->socialAccounts()
            ->where('platform', 'youtube')
            ->findOrFail($validated['account_id']);

        $scheduledAt = ! empty($validated['scheduled_at'])
            ? Carbon::parse($validated['scheduled_at'])
            : now();

        $hashtags = is_array($video->ai_hashtags)
            ? implode(' ', $video->ai_hashtags)
            : (string) ($video->ai_data['hashtags'] ?? '');

        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'custom_title' => $video->ai_title ?? $video->title,
            'custom_caption' => $video->ai_description ?? ($video->ai_data['caption'] ?? $video->title),
            'custom_hashtags' => $hashtags,
            'scheduled_at' => $scheduledAt,
            'status' => 'scheduled',
        ]);

        $video->update(['status' => 'scheduled']);

        return back()->with('success', 'Reupload queued — will upload to YouTube at '.$scheduledAt->format('M d, h:i A').'.');
    }

    public function analyze(Request $request, Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            abort(403);
        }

        $analyzer = app(GeminiVideoAnalyzer::class);
        if (! $analyzer->enabled($channel)) {
            return back()->withErrors(['gemini' => 'Gemini AI analysis is disabled for this channel. Enable it in Settings first.']);
        }

        // A completed analysis can be re-run explicitly; otherwise reuse it.
        $analysis = $analyzer->analyze($video);
        if ($analysis) {
            return back()->with('success', 'AI analysis complete — virality score '.$analysis['virality_score'].'/100.');
        }

        return back()->withErrors(['gemini' => 'AI analysis failed. The scheduled upload will use the existing metadata.']);
    }

    /**
     * Fetch fresh YouTube stats for this reel on demand (all published
     * versions with real watch URLs). Best-effort — a failed fetch never
     * errors out; it reports back so the user can reconnect if needed.
     */
    public function refreshStats(Request $request, Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            abort(403);
        }

        $video->load(['publications.socialAccount']);

        $candidates = $video->publications
            ->where('status', 'published')
            ->filter(fn ($p) => filled($p->post_url) && str_contains($p->post_url, 'watch?v='))
            ->values();

        if ($candidates->isEmpty()) {
            return back()->withErrors(['stats' => 'No published versions with real YouTube URLs yet — stats appear after a real upload.']);
        }

        $refreshed = 0;
        foreach ($candidates as $publication) {
            try {
                if (app(YouTubeAnalytics::class)->refresh($publication) !== null) {
                    $refreshed++;
                }
            } catch (Throwable) {
                // Best-effort — never break the page over a stats fetch.
            }
        }

        if ($refreshed > 0) {
            return back()->with('success', "Stats refreshed from YouTube for {$refreshed} published version(s).");
        }

        return back()->withErrors(['stats' => 'Stats could not be refreshed right now — check that the YouTube connection is still valid.']);
    }

    public function update(Request $request, Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            abort(403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'hook' => ['nullable', 'string'],
            'caption' => ['nullable', 'string'],
            'hashtags' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        $aiData = $video->ai_data ?? [];
        if (isset($validated['hook'])) {
            $aiData['hook'] = $validated['hook'];
        }
        if (isset($validated['caption'])) {
            $aiData['caption'] = $validated['caption'];
        }
        if (isset($validated['hashtags'])) {
            $aiData['hashtags'] = $validated['hashtags'];
        }

        $video->update([
            'title' => $validated['title'],
            'status' => $validated['status'] ?? $video->status,
            'ai_data' => $aiData,
        ]);

        return back()->with('success', 'Video details saved successfully.');
    }

    public function publish(Request $request, Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            abort(403);
        }

        $validated = $request->validate([
            'account_id' => ['required', 'exists:social_accounts,id'],
            'action_type' => ['required', 'in:publish_now,schedule'],
            'scheduled_at' => ['nullable', 'date'],
            'custom_caption' => ['nullable', 'string'],
            'custom_title' => ['nullable', 'string'],
        ]);

        $account = $channel->socialAccounts()->findOrFail($validated['account_id']);

        $status = $validated['action_type'] === 'publish_now' ? 'published' : 'scheduled';
        $scheduledDate = $validated['action_type'] === 'schedule' && ! empty($validated['scheduled_at'])
            ? Carbon::parse($validated['scheduled_at'])
            : ($validated['action_type'] === 'publish_now' ? null : $account->nextFreeSlot());

        $publishedDate = $validated['action_type'] === 'publish_now' ? Carbon::now() : null;

        $customCaption = $validated['custom_caption'] ?? ($video->ai_data['caption'] ?? $video->title);
        $customTitle = $validated['custom_title'] ?? $video->title;
        $hashtags = $video->ai_data['hashtags'] ?? '';

        // Publish Now actually uploads to YouTube when the account has real
        // OAuth credentials; otherwise it stays simulated (dev mode). A real
        // failure never fabricates a fake URL.
        $postUrl = null;
        if ($status === 'published') {
            $tags = collect(explode(' ', (string) $hashtags))
                ->map(fn ($tag) => ltrim(trim($tag), '#'))
                ->filter()
                ->values()
                ->all();

            try {
                $videoId = app(YouTubeUploader::class)->upload($account, $video, $customTitle, $customCaption, $tags);
            } catch (Throwable $e) {
                return back()->withErrors(['youtube' => 'YouTube upload failed: '.$e->getMessage()]);
            }

            $creds = $account->googleOAuthCredentials();
            $hasRealCreds = filled($creds['client_id']) && filled($creds['client_secret']);
            if (! $videoId && $hasRealCreds) {
                return back()->withErrors(['youtube' => 'YouTube upload could not complete (access token refresh failed).']);
            }

            $postUrl = $videoId
                ? 'https://www.youtube.com/watch?v='.$videoId
                : 'https://youtube.com/shorts/'.substr(md5(rand()), 0, 11);
        }

        $publication = Publication::updateOrCreate(
            [
                'video_id' => $video->id,
                'social_account_id' => $account->id,
            ],
            [
                'custom_title' => $customTitle,
                'custom_caption' => $customCaption,
                'custom_hashtags' => $hashtags,
                'scheduled_at' => $scheduledDate,
                'published_at' => $publishedDate,
                'status' => $status,
                'post_url' => $postUrl,
                // Real stats are fetched right after a real upload (or by the
                // twice-daily analytics:refresh) — never fabricated numbers.
                'analytics' => null,
            ]
        );

        $video->update([
            'status' => $status === 'published' ? 'published' : 'scheduled',
        ]);

        // Best-effort first stats fetch after a real upload; never blocks or
        // surfaces errors — the twice-daily analytics:refresh covers the rest.
        if ($status === 'published' && $videoId) {
            try {
                app(YouTubeAnalytics::class)->refresh($publication->fresh());
            } catch (Throwable) {
                // Ignore — stats are best-effort.
            }
        }

        $msg = $status === 'published'
            ? ($postUrl && str_contains($postUrl, 'watch?v=')
                ? 'Video uploaded to your YouTube channel!'
                : 'Video published (simulated — no YouTube OAuth configured).')
            : 'Video queued and scheduled for YouTube.';

        return redirect()->route('videos.index')->with('success', $msg);
    }

    public function simulateProgress(Request $request, Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $statusCycle = [
            'draft' => 'processing',
            'processing' => 'ready',
        ];

        $nextStatus = $statusCycle[$video->status] ?? 'ready';
        $video->update(['status' => $nextStatus]);

        return response()->json([
            'success' => true,
            'status' => $video->status,
            'status_label' => ucfirst(str_replace('_', ' ', $video->status)),
        ]);
    }

    public function destroy(Video $video)
    {
        $channel = Auth::user()->currentChannel();
        if ($video->channel_id !== $channel->id) {
            abort(403);
        }

        // Don't leave the file behind on disk — deleted videos should not
        // keep eating into the hosting plan's storage quota.
        if ($video->file_path) {
            Storage::disk($this->videoDisk())->delete($video->file_path);
        }

        $video->delete();

        return redirect()->route('videos.index')->with('success', 'Video deleted successfully.');
    }

    /**
     * Disk used for uploaded reel files. Defaults to "public" (local shared-
     * hosting disk); set VIDEO_DISK=ftp to keep files off the web plan.
     */
    private function videoDisk(): string
    {
        return (string) config('filesystems.video_disk', 'public');
    }
}
