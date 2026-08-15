<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoStorageTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithChannelAndYoutube(): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Test YT',
            'status' => 'connected',
        ]);

        return [$user, $channel, $account];
    }

    private function createPublishedVideo(int $channelId, int $accountId, string $filePath, int $daysAgo): Video
    {
        $video = Video::create([
            'channel_id' => $channelId,
            'title' => 'Reel',
            'file_path' => $filePath,
            'duration' => 30,
            'status' => 'published',
        ]);
        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $accountId,
            'scheduled_at' => now()->subDays($daysAgo),
            'published_at' => now()->subDays($daysAgo),
            'status' => 'published',
        ]);

        return $video;
    }

    public function test_upload_goes_to_configured_video_disk(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        config(['filesystems.video_disk' => 'ftp']);
        Storage::fake('ftp');

        $response = $this->actingAs($user)->post(route('videos.store'), [
            'title' => 'My Reel',
            'video_file' => UploadedFile::fake()->create('reel.mp4', 100, 'video/mp4'),
        ]);

        $response->assertRedirect();

        $video = Video::first();
        $this->assertNotNull($video->file_path);
        $this->assertStringStartsWith('videos/', $video->file_path);
        Storage::disk('ftp')->assertExists($video->file_path);
    }

    public function test_upload_defaults_to_public_disk(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');

        $this->actingAs($user)->post(route('videos.store'), [
            'title' => 'My Reel',
            'video_file' => UploadedFile::fake()->create('reel.mp4', 100, 'video/mp4'),
        ]);

        $video = Video::first();
        Storage::disk('public')->assertExists($video->file_path);
    }

    public function test_destroy_deletes_the_video_file(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Reel',
            'file_path' => 'videos/delete-me.mp4',
            'duration' => 30,
            'status' => 'ready',
        ]);
        Storage::disk('public')->put($video->file_path, 'fake-video-bytes');
        Storage::disk('public')->assertExists($video->file_path);

        $response = $this->actingAs($user)->delete(route('videos.destroy', $video));

        $response->assertRedirect(route('videos.index'));
        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
        Storage::disk('public')->assertMissing($video->file_path);
    }

    public function test_show_page_renders_video_player_when_file_exists(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Reel',
            'file_path' => 'videos/reel.mp4',
            'duration' => 36,
            'status' => 'ready',
        ]);
        Storage::disk('public')->put($video->file_path, 'video-bytes');

        $response = $this->actingAs($user)->get(route('videos.show', $video));

        $response->assertOk();
        $response->assertSee('<video', false);
        $response->assertSee('/storage/videos/reel.mp4', false);
    }

    public function test_show_page_falls_back_to_mockup_without_file(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Reel',
            'duration' => 36,
            'status' => 'ready',
        ]);

        $response = $this->actingAs($user)->get(route('videos.show', $video));

        $response->assertOk();
        $response->assertDontSee('<video', false);
        $response->assertSee('mockup-phone', false);
    }

    public function test_library_page_renders_inline_player_when_file_exists(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Reel',
            'file_path' => 'videos/reel.mp4',
            'duration' => 36,
            'status' => 'ready',
        ]);
        Storage::disk('public')->put($video->file_path, 'video-bytes');

        $response = $this->actingAs($user)->get(route('videos.index'));

        $response->assertOk();
        $response->assertSee('<video', false);
        $response->assertSee('class="library-video"', false);
        $response->assertSee('/storage/videos/reel.mp4', false);
        $response->assertSee('play-overlay', false);
    }

    public function test_library_page_keeps_mockup_without_file(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        Video::create([
            'channel_id' => $channel->id,
            'title' => 'Reel',
            'duration' => 36,
            'status' => 'ready',
        ]);

        $response = $this->actingAs($user)->get(route('videos.index'));

        $response->assertOk();
        $response->assertDontSee('<video', false);
        $response->assertSee('play-overlay', false);
    }

    public function test_prune_deletes_expired_published_files_and_keeps_recent(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');

        $old = $this->createPublishedVideo($channel->id, $account->id, 'videos/old.mp4', 20);
        $recent = $this->createPublishedVideo($channel->id, $account->id, 'videos/recent.mp4', 1);
        Storage::disk('public')->put($old->file_path, 'x');
        Storage::disk('public')->put($recent->file_path, 'y');

        $this->artisan('videos:prune-files', ['--retention-days' => 14])->assertExitCode(0);

        Storage::disk('public')->assertMissing($old->file_path);
        Storage::disk('public')->assertExists($recent->file_path);
        $this->assertNull($old->fresh()->file_path);
        $this->assertSame('videos/recent.mp4', $recent->fresh()->file_path);
    }

    public function test_prune_deletes_orphan_files(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');
        Storage::disk('public')->put('videos/orphan.mp4', 'x');

        $this->artisan('videos:prune-files')->assertExitCode(0);

        Storage::disk('public')->assertMissing('videos/orphan.mp4');
    }

    public function test_prune_dry_run_deletes_nothing(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');

        $old = $this->createPublishedVideo($channel->id, $account->id, 'videos/old.mp4', 20);
        Storage::disk('public')->put($old->file_path, 'x');
        Storage::disk('public')->put('videos/orphan.mp4', 'y');

        $this->artisan('videos:prune-files', ['--retention-days' => 14, '--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists($old->file_path);
        Storage::disk('public')->assertExists('videos/orphan.mp4');
        $this->assertSame('videos/old.mp4', $old->fresh()->file_path);
    }
}
