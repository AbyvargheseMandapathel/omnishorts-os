<?php

namespace App\Services;

use App\Exceptions\YouTubeUploadException;
use App\Models\SocialAccount;
use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads a reel to the connected YouTube channel via the Data API v3
 * (resumable protocol). Uses the account's own OAuth credentials and a fresh
 * access token. Returns null when no real credentials are configured — the
 * caller then falls back to the simulated publish (local/dev mode).
 */
class YouTubeUploader
{
    private const UPLOAD_ENDPOINT = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

    /**
     * @param  array<string>  $tags
     */
    public function upload(SocialAccount $account, Video $video, string $title, string $description, array $tags): ?string
    {
        $creds = $account->googleOAuthCredentials();
        if (! $creds['client_id'] || ! $creds['client_secret']) {
            // No real OAuth configured — local/simulated mode.
            return null;
        }

        $accessToken = $account->freshAccessToken();
        if (! $accessToken) {
            // Refresh failed; invalid_grant already flagged the account for
            // reconnect. Caller treats this as "could not upload".
            return null;
        }

        if (! $video->file_path || ! Storage::disk($this->videoDisk())->exists($video->file_path)) {
            throw new RuntimeException('Video file missing for YouTube upload.');
        }

        $mime = $this->mimeType($video);
        $bytes = Storage::disk($this->videoDisk())->get($video->file_path);
        if ($bytes === null) {
            throw new RuntimeException('Video file could not be read for YouTube upload.');
        }

        // Step 1: start a resumable upload session with the snippet/status metadata.
        $init = Http::withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) strlen($bytes),
                'X-Upload-Content-Type' => $mime,
            ])
            ->timeout(120)
            ->post(self::UPLOAD_ENDPOINT, [
                'snippet' => [
                    'title' => $title,
                    'description' => $description,
                    'tags' => array_values($tags),
                    'categoryId' => '22', // People & Blogs (Shorts)
                ],
                'status' => [
                    'privacyStatus' => 'public',
                    'madeForKids' => false, // Always mark as not made for kids.
                ],
            ]);

        if ($init->failed()) {
            $this->handleUploadFailure($account, $init->status(), $init->json('error.errors.0.reason'));
            throw new YouTubeUploadException(
                'YouTube rejected the upload request (HTTP '.$init->status().').',
                $this->isTransientStatus($init->status())
            );
        }

        $sessionUri = $init->header('Location');
        if (! $sessionUri) {
            throw new RuntimeException('YouTube did not return an upload session URL.');
        }

        // Step 2: stream the video bytes to the session URL.
        $upload = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => $mime])
            ->withBody($bytes, $mime)
            ->timeout(600)
            ->put($sessionUri);

        if ($upload->failed()) {
            $this->handleUploadFailure($account, $upload->status(), $upload->json('error.errors.0.reason'));
            throw new YouTubeUploadException(
                'YouTube video upload failed (HTTP '.$upload->status().').',
                $this->isTransientStatus($upload->status())
            );
        }

        $videoId = $upload->json('id');
        if (! is_string($videoId) || $videoId === '') {
            throw new YouTubeUploadException('YouTube upload succeeded but returned no video id.');
        }

        return $videoId;
    }

    private function handleUploadFailure(SocialAccount $account, int $status, ?string $reason): void
    {
        // A revoked/expired refresh token shows up as invalid_grant — flag the
        // account so the user reconnects and the post re-queues automatically.
        if ($status === 401 && $reason === 'invalid_grant') {
            $account->markNeedsReconnect();

            return;
        }

        if ($reason === 'quotaExceeded' || $reason === 'dailyLimitExceeded') {
            throw new YouTubeUploadException('YouTube upload quota exceeded — retrying later.', true);
        }
    }

    /**
     * HTTP statuses that are expected to resolve themselves — the scheduler
     * retries these with backoff instead of killing the post.
     */
    private function isTransientStatus(int $status): bool
    {
        return $status >= 500 || $status === 429;
    }

    private function videoDisk(): string
    {
        return (string) config('filesystems.video_disk', 'public');
    }

    private function mimeType(Video $video): string
    {
        $extension = strtolower(pathinfo((string) $video->file_path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'webm' => 'video/webm',
            default => 'video/mp4',
        };
    }
}
