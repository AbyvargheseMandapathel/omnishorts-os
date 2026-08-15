<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Services\PlaceholderVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnsureVideoFilesTest extends TestCase
{
    use RefreshDatabase;

    private function makeVideo(?string $filePath): Video
    {
        $channel = Channel::create(['user_id' => User::factory()->create()->id, 'name' => 'Test']);

        return Video::create([
            'channel_id' => $channel->id,
            'title' => 'Reel',
            'file_path' => $filePath,
            'duration' => 30,
            'status' => 'ready',
        ]);
    }

    public function test_creates_placeholder_for_file_less_videos(): void
    {
        Storage::fake('public');
        $missing = $this->makeVideo(null);                    // never had a file
        $gone = $this->makeVideo('videos/gone.mp4');          // file missing on disk
        $ok = $this->makeVideo('videos/ok.mp4');
        Storage::disk('public')->put('videos/ok.mp4', 'x');

        $this->artisan('videos:ensure-files')->assertExitCode(0);

        $this->assertNotNull($missing->fresh()->file_path);
        Storage::disk('public')->assertExists($missing->fresh()->file_path);
        $this->assertSame(PlaceholderVideo::DURATION_SECONDS, $missing->fresh()->duration);

        $this->assertNotNull($gone->fresh()->file_path);
        Storage::disk('public')->assertExists($gone->fresh()->file_path);

        // Videos that already have a file are untouched.
        $this->assertSame('videos/ok.mp4', $ok->fresh()->file_path);
    }

    public function test_generated_placeholder_is_a_valid_mp4(): void
    {
        Storage::fake('public');
        $video = $this->makeVideo(null);

        $this->artisan('videos:ensure-files')->assertExitCode(0);

        $bytes = Storage::disk('public')->get($video->fresh()->file_path);
        $this->assertStringStartsWith("\x00\x00\x00\x20ftyp", $bytes);
        $this->assertStringContainsString('moov', $bytes);
        $this->assertStringContainsString('avc1', $bytes);

        // getID3 (already a dependency) must parse it as a real mp4/avc1 file.
        $tmp = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($tmp, $bytes);
        $info = (new \getID3())->analyze($tmp);
        @unlink($tmp);

        $this->assertSame('mp4', $info['fileformat'] ?? null);
        $this->assertSame('avc1', $info['video']['fourcc'] ?? null);
        $this->assertSame(PlaceholderVideo::WIDTH, $info['video']['resolution_x'] ?? null);
        $this->assertSame(PlaceholderVideo::HEIGHT, $info['video']['resolution_y'] ?? null);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Storage::fake('public');
        $video = $this->makeVideo(null);

        $this->artisan('videos:ensure-files', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull($video->fresh()->file_path);
    }

    public function test_idle_when_all_videos_have_files(): void
    {
        Storage::fake('public');
        $video = $this->makeVideo('videos/ok.mp4');
        Storage::disk('public')->put('videos/ok.mp4', 'x');

        $this->artisan('videos:ensure-files')->assertExitCode(0);

        $this->assertSame('videos/ok.mp4', $video->fresh()->file_path);
    }

    public function test_fails_loudly_when_disk_cannot_write(): void
    {
        config([
            'filesystems.video_disk' => 'broken',
            'filesystems.disks.broken' => [
                'driver' => 'local',
                'root' => base_path('composer.json').'/videos',
                'throw' => false,
            ],
        ]);
        $video = $this->makeVideo(null);

        $this->artisan('videos:ensure-files')->assertExitCode(1);

        $this->assertNull($video->fresh()->file_path);
    }
}
