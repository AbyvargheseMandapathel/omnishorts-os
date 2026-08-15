<?php

namespace Tests\Fakes;

use App\Models\AiVideoJob;
use App\Services\Ai\VideoRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * Replaces the real ffmpeg renderer in pipeline tests: writes a fake final
 * file so the rest of the flow (approve, preview URL, library) works.
 */
class FakeVideoRenderer extends VideoRenderer
{
    public int $calls = 0;

    public function render(AiVideoJob $job): string
    {
        $this->calls++;

        $finalPath = 'videos/ai-'.$job->id.'.mp4';
        Storage::disk((string) config('filesystems.video_disk', 'public'))->put($finalPath, 'fake-rendered-video-bytes');
        $job->update(['final_path' => $finalPath]);

        return $finalPath;
    }
}
