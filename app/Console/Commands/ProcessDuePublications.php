<?php

namespace App\Console\Commands;

use App\Exceptions\YouTubeUploadException;
use App\Models\Publication;
use App\Models\Setting;
use App\Models\Video;
use App\Services\GeminiVideoAnalyzer;
use App\Services\YouTubeAnalytics;
use App\Services\YouTubeUploader;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ProcessDuePublications extends Command
{
    /** Give up after this many attempts; before that, transient failures
     * are re-queued with exponential backoff (5, 10, 20, 40, 80 min). */
    private const MAX_ATTEMPTS = 5;

    protected $signature = 'publications:process-due';

    protected $description = 'Publish all scheduled publications whose scheduled_at time has arrived';

    public function handle(): int
    {
        // Heartbeat for the dashboard cron status widget — a stale timestamp
        // means the scheduler has stopped running.
        Setting::set('cron.last_checked', now()->toDateTimeString());

        $due = Publication::with(['video', 'socialAccount'])
            ->where('status', 'scheduled')
            ->where(function ($query) {
                // Due either by original schedule or by a retry backoff timer.
                $query->where('scheduled_at', '<=', now())
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->get();

        if ($due->isEmpty()) {
            $this->info('No due publications.');

            return self::SUCCESS;
        }

        $publishedCount = 0;
        $failedCount = 0;
        $retriedCount = 0;

        // All external work (token refresh, Gemini analysis, YouTube upload)
        // happens OUTSIDE any DB transaction: long HTTP calls must never hold
        // an SQLite write lock, or web requests start failing with "database
        // is locked". Only the short final state update is transactional.
        foreach ($due as $publication) {
            try {
                $this->ensureFreshYouTubeToken($publication);

                // Gemini analysis runs NOW, at upload time, so the upload
                // always gets fresh AI metadata. Failure falls back to the
                // existing metadata — an AI problem must never lose a
                // scheduled upload. A completed analysis is reused on
                // retries instead of calling Gemini again.
                $analysis = $this->geminiAnalysisForUpload($publication->video);
                $video = $publication->video->fresh();
                $title = $analysis['title'] ?? $video->title;
                $caption = $analysis['description'] ?? ($video->ai_data['caption'] ?? $video->title);
                $hashtagString = is_array($analysis['hashtags'] ?? null)
                    ? implode(' ', $analysis['hashtags'])
                    : (string) ($video->ai_data['hashtags'] ?? '');
                $hashtags = is_array($analysis['hashtags'] ?? null)
                    ? $analysis['hashtags']
                    : collect(explode(' ', (string) ($video->ai_data['hashtags'] ?? '')))
                        ->map(fn ($tag) => ltrim(trim($tag), '#'))
                        ->filter()
                        ->values()
                        ->all();

                // Real YouTube upload via the account's OAuth credentials;
                // falls back to the simulated publish only when no real
                // credentials are configured (local/dev mode).
                $postUrl = $this->uploadToYouTube($publication, $video, $title, $caption, $hashtags)
                    ?? 'https://youtube.com/shorts/'.substr(md5((string) $publication->id.uniqid()), 0, 11);

                DB::transaction(function () use ($publication, $video, $title, $caption, $hashtagString, $postUrl) {
                    $publication->update([
                        'status' => 'published',
                        'published_at' => now(),
                        'custom_title' => $title,
                        'custom_caption' => trim($caption.($hashtagString ? "\n\n".$hashtagString : '')),
                        'custom_hashtags' => $hashtagString,
                        'post_url' => $postUrl,
                        'analytics' => null, // Real stats arrive right after (or via analytics:refresh).
                        'attempt_count' => 0,
                        'next_retry_at' => null,
                    ]);

                    $video->update(['status' => 'published']);
                });

                // Best-effort first stats fetch right after a successful upload;
                // the hourly analytics:refresh keeps them fresh. Never blocks.
                try {
                    app(YouTubeAnalytics::class)->refresh($publication->fresh());
                } catch (\Throwable) {
                    // Ignore — stats are best-effort.
                }

                $publishedCount++;
            } catch (\Throwable $e) {
                if ($this->isTransientFailure($e) && $publication->attempt_count < self::MAX_ATTEMPTS) {
                    // Transient (quota, 5xx, timeout, network): re-queue with
                    // exponential backoff instead of killing the post.
                    $attempt = $publication->attempt_count + 1;
                    $backoffMinutes = min(5 * (2 ** ($attempt - 1)), 120);
                    DB::transaction(fn () => $publication->update([
                        'attempt_count' => $attempt,
                        'next_retry_at' => now()->addMinutes($backoffMinutes),
                    ]));
                    $retriedCount++;
                    $this->warn("Publish #{$publication->id} failed (transient) — retry #{$attempt} in {$backoffMinutes} min: {$e->getMessage()}");
                } else {
                    DB::transaction(fn () => $publication->update(['status' => 'failed']));
                    $failedCount++;
                    $this->error("Failed to publish #{$publication->id}: {$e->getMessage()}");
                }
            }
        }

        $message = "Published {$publishedCount} publication(s).";
        if ($retriedCount > 0) {
            $message .= " {$retriedCount} queued for retry (transient failure).";
        }
        if ($failedCount > 0) {
            $message .= " {$failedCount} failed.";
            $this->error($message);

            return self::FAILURE;
        }

        $this->info($message);

        return self::SUCCESS;
    }

    /**
     * Whether a publish failure should be retried with backoff. Timeouts and
     * network errors are always transient; the uploader marks quota/5xx/429
     * explicitly. Everything else (invalid grant, forbidden, missing file)
     * is permanent.
     */
    private function isTransientFailure(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof YouTubeUploadException) {
            return $e->transient;
        }

        return false;
    }

    /**
     * A real upload needs a fresh access token. Exchange the stored refresh
     * token first; if Google answers with invalid_grant the token was revoked,
     * so flag the account for a one-click reconnect instead of silently
     * retrying with a dead token forever.
     */
    private function ensureFreshYouTubeToken(Publication $publication): void
    {
        $account = $publication->socialAccount;

        if (! $account || $account->platform !== 'youtube') {
            return;
        }

        $creds = $account->googleOAuthCredentials();
        $refreshToken = $account->credentials['refresh_token'] ?? null;

        // No credentials configured (e.g. local/simulated mode) — nothing to refresh.
        if (! $creds['client_id'] || ! $creds['client_secret'] || ! $refreshToken) {
            return;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ]);

        if ($response->successful()) {
            return;
        }

        $error = $response->json('error');

        if ($error === 'invalid_grant') {
            $account->markNeedsReconnect();

            // Flag this post so it is re-queued automatically once the user
            // reconnects the account (see SocialAccountController).
            $publication->update(['status' => 'failed', 'retry_on_reconnect' => true]);

            throw new \RuntimeException(
                "YouTube access expired for {$account->account_name} — reconnect to resume publishing."
            );
        }

        throw new \RuntimeException('Could not refresh YouTube token: '.($error ?? 'unknown error'));
    }

    /**
     * Upload the video to YouTube for real. Returns the public watch URL, or
     * null when the account has no real OAuth credentials (simulated mode).
     * On invalid_grant the account is flagged for reconnect and the post is
     * re-queued automatically.
     */
    private function uploadToYouTube(Publication $publication, Video $video, string $title, string $caption, array $hashtags): ?string
    {
        $account = $publication->socialAccount;
        if (! $account || $account->platform !== 'youtube') {
            return null;
        }

        try {
            $videoId = app(YouTubeUploader::class)->upload(
                $account,
                $video,
                $title,
                trim($caption.($hashtags ? "\n\n".implode(' ', $hashtags) : '')),
                $hashtags
            );
        } catch (\Throwable $e) {
            if ($account->status === 'expired') {
                // Refresh token revoked — reconnect and re-queue this post.
                $publication->update(['status' => 'failed', 'retry_on_reconnect' => true]);
            }

            throw $e;
        }

        if ($videoId === null) {
            if ($account->status === 'expired') {
                // Refresh token revoked — reconnect and re-queue this post.
                $publication->update(['status' => 'failed', 'retry_on_reconnect' => true]);
                throw new \RuntimeException("YouTube access expired for {$account->account_name} — reconnect to resume publishing.");
            }

            $creds = $account->googleOAuthCredentials();
            $hasRealCreds = filled($creds['client_id']) && filled($creds['client_secret']);

            if ($hasRealCreds) {
                // Real credentials but no upload — never fabricate a fake URL.
                throw new \RuntimeException('YouTube upload could not complete (access token refresh failed).');
            }

            return null;
        }

        return 'https://www.youtube.com/watch?v='.$videoId;
    }

    /**
     * Gemini analysis for a video about to be uploaded. Returns the analysis
     * array, or null to signal "use the existing metadata". Never throws.
     *
     * @return array<string, mixed>|null
     */
    private function geminiAnalysisForUpload(Video $video): ?array
    {
        $analyzer = app(GeminiVideoAnalyzer::class);

        if (! $analyzer->enabled()) {
            return null;
        }

        // Reuse a successful analysis from an earlier attempt (e.g. cron
        // retried after the YouTube upload failed) — no duplicate AI calls.
        if ($video->analysis_status === 'completed') {
            return $analyzer->storedAnalysis($video);
        }

        return $analyzer->analyze($video);
    }
}
