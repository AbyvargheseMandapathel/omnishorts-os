<?php

namespace App\Services\Ai;

use App\Models\AiVideoJob;
use App\Models\Video;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a completed AI job's rendered file into a normal Content Library
 * Video row — the hand-off point to the existing scheduling / publishing
 * workflow. Used by both the manual Approve button and the daily
 * auto-generation flow (auto_approve jobs).
 */
class VideoApprover
{
    /**
     * @throws AiProviderException When the job is not ready to approve.
     */
    public function approve(AiVideoJob $job): Video
    {
        if ($job->status !== AiVideoJob::STATUS_COMPLETED || ! $job->final_path) {
            throw AiProviderException::permanent('The video is not ready to approve yet.');
        }

        $disk = (string) config('filesystems.video_disk', 'public');
        if (! Storage::disk($disk)->exists($job->final_path)) {
            throw AiProviderException::permanent('The rendered file is missing from the video disk — re-render it.');
        }

        if ($job->video_id) {
            return $job->video;
        }

        $script = $job->script ?? [];
        $title = $job->title ?: ($script['title'] ?? $job->topic);
        $description = $job->description ?: ($script['description'] ?? '');

        $video = $job->channel->videos()->create([
            'title' => $title,
            'description' => $description,
            'file_path' => $job->final_path,
            'duration' => (int) round((float) ($job->voice['duration'] ?? 0)),
            'status' => 'ready',
            'ai_data' => [
                'hook' => $title,
                'caption' => $description ?: $title,
                'hashtags' => '',
                'generated_by_ai' => true,
                'ai_job_id' => $job->id,
            ],
        ]);

        $job->update(['video_id' => $video->id]);

        return $video;
    }
}
