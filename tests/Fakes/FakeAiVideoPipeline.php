<?php

namespace Tests\Fakes;

use App\Models\AiVideoJob;
use App\Services\Ai\AiVideoPipeline;
use Illuminate\Support\Facades\Storage;

/**
 * Test double for the pipeline: marks the job completed and writes a final
 * file on the video disk, without touching any provider or ffmpeg.
 */
class FakeAiVideoPipeline extends AiVideoPipeline
{
    public function process(AiVideoJob $job): void
    {
        $path = 'ai_daily/final-'.$job->id.'.mp4';
        Storage::disk('public')->put($path, 'fake-mp4');

        $job->update([
            'status' => AiVideoJob::STATUS_COMPLETED,
            'final_path' => $path,
            'stage' => null,
            'completed_at' => now(),
            'voice' => ['duration' => 5],
        ]);
    }
}
