<?php

namespace App\Console\Commands;

use App\Models\Publication;
use App\Services\YouTubeAnalytics;
use Illuminate\Console\Command;

class RefreshAnalytics extends Command
{
    protected $signature = 'analytics:refresh';

    protected $description = 'Pull fresh view/like/comment/share stats for published reels from YouTube';

    public function handle(): int
    {
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
                if (app(YouTubeAnalytics::class)->refresh($publication) !== null) {
                    $refreshed++;
                }
            } catch (\Throwable) {
                // Stats are best-effort — a failed fetch never breaks the cron.
            }
        }

        $this->info("Refreshed analytics for {$refreshed} of {$publications->count()} published reel(s).");

        return self::SUCCESS;
    }
}
