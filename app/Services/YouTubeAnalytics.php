<?php

namespace App\Services;

use App\Models\Publication;
use App\Models\PublicationMetric;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

/**
 * Pulls real engagement stats for a published reel from YouTube:
 *
 *  1. YouTube Analytics API v2 (youtubeanalytics.googleapis.com) — gives
 *     views, likes, comments AND shares. Needs the yt-analytics.readonly
 *     scope (the connect flow already requests it) and the account's
 *     YouTube channel id.
 *  2. Fallback: Data API v3 videos.list?part=statistics — views/likes/
 *     comments only (no shares), works for any authorized token.
 *
 * Every successful fetch appends a snapshot row to publication_metrics (the
 * dashboard growth curve) and updates Publication::analytics with the latest
 * values. Failures return null — stats are best-effort and never block
 * publishing.
 */
class YouTubeAnalytics
{
    private const ANALYTICS_ENDPOINT = 'https://youtubeanalytics.googleapis.com/v2/reports';

    private const VIDEOS_ENDPOINT = 'https://www.googleapis.com/youtube/v3/videos';

    public function refresh(Publication $publication): ?array
    {
        $account = $publication->socialAccount;
        $videoId = $this->videoIdFromUrl($publication->post_url);

        if (! $account || $account->platform !== 'youtube' || ! $videoId) {
            return null;
        }

        // Mirrors the uploader: real OAuth credentials gate real API calls.
        // Without them (local/simulated mode) there is nothing to fetch.
        $creds = $account->googleOAuthCredentials();
        if (! filled($creds['client_id']) || ! filled($creds['client_secret'])) {
            return null;
        }

        $stats = $this->fetchStats($account, $videoId);
        if ($stats === null) {
            return null;
        }

        PublicationMetric::create([
            'publication_id' => $publication->id,
            'views' => $stats['views'],
            'likes' => $stats['likes'],
            'comments' => $stats['comments'],
            'shares' => $stats['shares'],
            'fetched_at' => now(),
        ]);

        $publication->update(['analytics' => $stats]);

        return $stats;
    }

    /**
     * @return array{views: int, likes: int, comments: int, shares: int}|null
     */
    private function fetchStats(SocialAccount $account, string $videoId): ?array
    {
        $accessToken = $account->freshAccessToken();
        if (! $accessToken) {
            return null;
        }

        $channelId = $account->credentials['youtube_channel_id'] ?? null;

        // Analytics API v2 gives shares too — use it when we know the
        // account's YouTube channel id.
        if ($channelId) {
            $stats = $this->analyticsReport($accessToken, $channelId, $videoId);
            if ($stats !== null) {
                return $stats;
            }
        }

        // Fallback: Data API statistics (no shares).
        return $this->statisticsList($accessToken, $videoId);
    }

    /**
     * @return array{views: int, likes: int, comments: int, shares: int}|null
     */
    private function analyticsReport(string $accessToken, string $channelId, string $videoId): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->get(self::ANALYTICS_ENDPOINT, [
                    'ids' => 'channel=='.$channelId,
                    'startDate' => now()->subDays(30)->toDateString(),
                    'endDate' => now()->toDateString(),
                    'metrics' => 'views,likes,comments,shares',
                    'filters' => 'video=='.$videoId,
                ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $rows = $response->json('rows', []);
        if (count($rows) === 0) {
            return null;
        }

        [$views, $likes, $comments, $shares] = array_pad(array_map('intval', $rows[0]), 4, 0);

        return compact('views', 'likes', 'comments', 'shares');
    }

    /**
     * @return array{views: int, likes: int, comments: int, shares: int}|null
     */
    private function statisticsList(string $accessToken, string $videoId): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->get(self::VIDEOS_ENDPOINT, [
                    'part' => 'statistics',
                    'id' => $videoId,
                ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $stats = $response->json('items.0.statistics');
        if (! is_array($stats)) {
            return null;
        }

        return [
            'views' => (int) ($stats['viewCount'] ?? 0),
            'likes' => (int) ($stats['likeCount'] ?? 0),
            'comments' => (int) ($stats['commentCount'] ?? 0),
            'shares' => 0, // Not available via videos.list.
        ];
    }

    private function videoIdFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (preg_match('/[?&]v=([A-Za-z0-9_-]{6,})/', $url, $matches)) {
            return $matches[1];
        }

        if (preg_match('#youtu\.be/([A-Za-z0-9_-]{6,})#', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
