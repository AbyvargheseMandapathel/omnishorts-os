<?php

namespace App\Console\Commands;

use App\Models\Publication;
use App\Models\SocialAccount;
use App\Services\YouTubeAnalytics;
use Illuminate\Console\Command;

class RefreshAnalytics extends Command
{
    protected $signature = 'analytics:refresh';

    protected $description = 'Pull fresh view/like/comment/share stats and subscriber counts from YouTube';

    public function handle(): int
    {
        $analytics = app(YouTubeAnalytics::class);

        $publications = Publication::with('socialAccount')
            ->where('status', 'published')
            ->whereNotNull('post_url')
            ->where('post_url', 'like', '%youtube.com/watch?v=%')
            ->latest('published_at')
            ->take(200)
            ->get();

        $refreshed = 0;
        foreach ($publications as $publication) {
            try {
                if ($analytics->refresh($publication) !== null) {
                    $refreshed++;
                }
            } catch (\Throwable) {
                // Stats are best-effort — a failed fetch never breaks the cron.
            }
        }

        // Keep the dashboard's subscriber numbers current — a connected
        // account's follower_count is otherwise stale from connect time.
        $accounts = SocialAccount::query()
            ->where('platform', 'youtube')
            ->where('status', 'connected')
            ->get();
        $accountsRefreshed = 0;
        foreach ($accounts as $account) {
            try {
                if ($analytics->refreshAccount($account)) {
                    $accountsRefreshed++;
                }
            } catch (\Throwable) {
                // Best-effort — never break the refresh over one account.
            }
        }

        $this->info("Refreshed analytics for {$refreshed} of {$publications->count()} published reel(s) and {$accountsRefreshed} of {$accounts->count()} channel(s).");

        return self::SUCCESS;
    }
}
