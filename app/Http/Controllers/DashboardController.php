<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Models\PublicationMetric;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $channel = $user->currentChannel();

        if (! $channel) {
            return redirect()->route('onboarding.welcome');
        }

        $totalVideos = $channel->videos()->count();
        $readyVideos = $channel->videos()->where('status', 'ready')->count();
        $scheduledCount = Publication::whereHas('video', function ($q) use ($channel) {
            $q->where('channel_id', $channel->id);
        })->where('status', 'scheduled')->count();

        $publishedCount = Publication::whereHas('video', function ($q) use ($channel) {
            $q->where('channel_id', $channel->id);
        })->where('status', 'published')->count();

        $youtubeAccounts = $channel->socialAccounts()
            ->where('platform', 'youtube')
            ->where('status', 'connected')
            ->get();
        $totalSubscribers = $youtubeAccounts->sum('follower_count');

        // Accounts whose Google refresh token was revoked (invalid_grant) —
        // surface a one-click reconnect banner.
        $reconnectAccounts = $channel->socialAccounts()
            ->where('platform', 'youtube')
            ->where('status', '!=', 'connected')
            ->get();

        $recentVideos = $channel->videos()->latest()->take(6)->get();

        // Real analytics for every published reel of this channel. Values are
        // fetched from YouTube after upload (analytics:refresh runs hourly)
        // — never fabricated.
        $publishedPublications = Publication::with(['video', 'socialAccount'])
            ->whereHas('video', fn ($q) => $q->where('channel_id', $channel->id))
            ->where('status', 'published')
            ->whereNotNull('post_url')
            ->get();

        $totalViews = (int) $publishedPublications->sum(fn ($p) => (int) ($p->analytics['views'] ?? 0));
        $totalLikes = (int) $publishedPublications->sum(fn ($p) => (int) ($p->analytics['likes'] ?? 0));
        $totalComments = (int) $publishedPublications->sum(fn ($p) => (int) ($p->analytics['comments'] ?? 0));
        $totalShares = (int) $publishedPublications->sum(fn ($p) => (int) ($p->analytics['shares'] ?? 0));

        $bestPerformers = $publishedPublications
            ->filter(fn ($p) => (int) ($p->analytics['views'] ?? 0) > 0)
            ->sortByDesc(fn ($p) => (int) $p->analytics['views'])
            ->take(5)
            ->values();

        // Growth curve: cumulative views observed per day across this
        // channel's reels, from the metric snapshot history (last 14 days).
        $viewsCurve = PublicationMetric::query()
            ->whereIn('publication_id', $publishedPublications->pluck('id')->all())
            ->where('fetched_at', '>=', now()->subDays(14))
            ->orderBy('fetched_at')
            ->get()
            ->groupBy(fn ($m) => $m->fetched_at->format('Y-m-d'))
            ->map(fn ($group) => (int) $group->sum('views'))
            ->values()
            ->all();

        $upcomingPublications = Publication::with(['video', 'socialAccount'])
            ->whereHas('video', function ($q) use ($channel) {
                $q->where('channel_id', $channel->id);
            })
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at', 'asc')
            ->take(5)
            ->get();

        $allChannels = $user->channels()->get();

        // Cron / scheduler health: the commands stamp cron.last_checked on
        // every run, so a stale timestamp means the scheduler stopped.
        $lastCronCheck = Setting::get('cron.last_checked');
        $lastCronCheckAt = $lastCronCheck ? Carbon::parse($lastCronCheck) : null;
        $cronHealthy = $lastCronCheckAt !== null && $lastCronCheckAt->greaterThanOrEqualTo(now()->subMinutes(10));

        return view('dashboard.index', compact(
            'channel',
            'totalVideos',
            'readyVideos',
            'scheduledCount',
            'publishedCount',
            'youtubeAccounts',
            'reconnectAccounts',
            'totalSubscribers',
            'totalViews',
            'totalLikes',
            'totalComments',
            'totalShares',
            'bestPerformers',
            'viewsCurve',
            'recentVideos',
            'upcomingPublications',
            'allChannels',
            'lastCronCheckAt',
            'cronHealthy'
        ));
    }

    /**
     * Manually trigger the publish cron (same command the scheduler runs
     * every minute). Useful for testing / verifying the pipeline.
     */
    public function runCron()
    {
        $exitCode = Artisan::call('publications:process-due');
        $output = trim(Artisan::output());

        if ($exitCode === 0) {
            return back()->with('success', $output ?: 'Cron ran successfully.');
        }

        return back()->with('error', $output ?: 'Cron finished with errors.');
    }
}
