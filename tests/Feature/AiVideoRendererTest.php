<?php

namespace Tests\Feature;

use App\Models\AiVideoJob;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\VideoRenderer;
use App\Services\PlaceholderVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Renders a real MP4 with ffmpeg end to end: background + images + voice +
 * captions. Skipped when no ffmpeg is on the machine (CI / minimal hosts).
 */
class AiVideoRendererTest extends TestCase
{
    use RefreshDatabase;

    private const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not available on this machine.');
        }
    }

    public function test_renders_final_mp4_from_background_images_voice_and_captions(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        // Real disks: the renderer (and ffmpeg) read real files.
        config(['filesystems.video_disk' => 'public']);

        $job = AiVideoJob::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'content_type' => 'video',
            'topic' => 'Test render',
            'background_path' => 'ai_backgrounds/bg.mp4',
            'background_duration' => 1.0, // shorter than narration → loop path
            'background_width' => 720,
            'background_height' => 1280,
            'status' => 'queued',
            'stage' => 'render',
            'voice' => [
                'duration' => 1.5,
                'sentences' => [
                    ['text' => 'First scene.', 'offset_ms' => 0, 'duration_ms' => 500],
                    ['text' => 'Second scene.', 'offset_ms' => 500, 'duration_ms' => 500],
                    ['text' => 'Third scene.', 'offset_ms' => 1000, 'duration_ms' => 500],
                ],
            ],
        ]);
        // Scene timings: 0–0.5, 0.5–1.0, 1.0–1.5
        $job->update(['scenes' => [
            ['scene_number' => 1, 'narration' => 'First scene.', 'image_prompt' => 'P1', 'image_path' => 'jobs/'.$job->id.'/images/scene-01.png', 'image_status' => 'done', 'start_time' => 0.0, 'end_time' => 0.5],
            ['scene_number' => 2, 'narration' => 'Second scene.', 'image_prompt' => 'P2', 'image_path' => 'jobs/'.$job->id.'/images/scene-02.png', 'image_status' => 'done', 'start_time' => 0.5, 'end_time' => 1.0],
            ['scene_number' => 3, 'narration' => 'Third scene.', 'image_prompt' => 'P3', 'image_path' => 'jobs/'.$job->id.'/images/scene-03.png', 'image_status' => 'done', 'start_time' => 1.0, 'end_time' => 1.5],
        ]]);

        $workDir = storage_path('app/ai/jobs/'.$job->id);
        foreach (['', '/images', '/voice', '/captions'] as $sub) {
            $dir = $workDir.$sub;
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        // 1s black 720x1280 background (proven-valid MP4).
        file_put_contents($workDir.'/background_src.mp4', PlaceholderVideo::mp4());

        // 3 scene PNGs (1x1, upscaled by ffmpeg).
        foreach ([1, 2, 3] as $n) {
            file_put_contents($workDir."/images/scene-0{$n}.png", base64_decode(self::TINY_PNG, true));
        }

        // 1.5s silent narration MP3.
        $this->makeSilenceMp3($workDir.'/voice/narration.mp3', 1.5);

        // Captions.
        $captionsPath = 'jobs/'.$job->id.'/captions/subtitles.srt';
        file_put_contents($workDir.'/captions/subtitles.srt', "1\n00:00:00,000 --> 00:00:00,500\nFirst scene.\n\n2\n00:00:00,500 --> 00:00:01,000\nSecond scene.\n\n3\n00:00:01,000 --> 00:00:01,500\nThird scene.\n\n");
        $job->update(['captions_path' => $captionsPath]);

        try {
            $finalPath = app(VideoRenderer::class)->render($job);
        } catch (AiProviderException $e) {
            $this->fail('Render failed: '.$e->getMessage());
        }

        $this->assertSame('videos/ai-'.$job->id.'.mp4', $finalPath);
        $this->assertTrue(Storage::disk('public')->exists($finalPath), 'Rendered file must land on the video disk');

        // Verify the output with ffprobe: h264 720x1280 + aac, ~1.5s.
        $probe = $this->ffprobe(Storage::disk('public')->path($finalPath));
        $this->assertNotNull($probe, 'ffprobe could not read the rendered file');
        $this->assertGreaterThanOrEqual(1.4, (float) $probe['duration'], 'Final duration must match the narration');
        $this->assertLessThanOrEqual(1.7, (float) $probe['duration']);

        $videoStream = collect($probe['streams'])->first(fn ($s) => ($s['codec_type'] ?? '') === 'video');
        $audioStream = collect($probe['streams'])->first(fn ($s) => ($s['codec_type'] ?? '') === 'audio');

        $this->assertNotNull($videoStream, 'Output must have a video stream');
        $this->assertSame('h264', $videoStream['codec_name'] ?? null);
        $this->assertSame(720, $videoStream['width'] ?? null);
        $this->assertSame(1280, $videoStream['height'] ?? null);
        $this->assertNotNull($audioStream, 'Output must have the narration audio');
        $this->assertSame('aac', $audioStream['codec_name'] ?? null);
    }

    private function makeSilenceMp3(string $path, float $seconds): void
    {
        $ffmpeg = config('ai.ffmpeg_binary');
        $command = [$ffmpeg, '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'anullsrc=r=24000:cl=mono',
            '-t', number_format($seconds, 3, '.', ''),
            '-c:a', 'libmp3lame', '-b:a', '48k', $path];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        $this->assertFileExists($path);
    }

    private function ffmpegAvailable(): bool
    {
        $found = DIRECTORY_SEPARATOR === '\\'
            ? shell_exec('where ffmpeg 2>nul')
            : shell_exec('command -v ffmpeg 2>/dev/null');

        return is_string($found) && trim($found) !== '';
    }

    /**
     * @return array{duration: float, streams: array<int, array<string, mixed>>}|null
     */
    private function ffprobe(string $path): ?array
    {
        $command = [config('ai.ffprobe_binary'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            return null;
        }

        $data = json_decode((string) $stdout, true);

        return is_array($data) ? [
            'duration' => (float) ($data['format']['duration'] ?? 0),
            'streams' => $data['streams'] ?? [],
        ] : null;
    }
}
