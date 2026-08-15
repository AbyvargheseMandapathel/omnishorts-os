<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class YouTubeUploadTest extends TestCase
{
    use RefreshDatabase;

    private function createFixture(): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'AnimeWorld Daily',
            'status' => 'connected',
            'google_client_id' => 'client-123.apps.googleusercontent.com',
            'google_client_secret' => 'secret-123',
            'credentials' => ['refresh_token' => 'healthy-refresh-token'],
        ]);
        Storage::fake('public');
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'My Short',
            'status' => 'scheduled',
            'file_path' => 'videos/reel.mp4',
        ]);
        Storage::disk('public')->put('videos/reel.mp4', 'fake-video-bytes');

        return [$user, $channel, $account, $video];
    }

    private function fakeYouTube(int $initStatus = 200, ?array $initBody = null, ?string $sessionBody = null): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-token',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response($initBody ?? '', $initStatus, [
                'Location' => 'https://upload.example.com/youtube-session',
            ]),
            'upload.example.com/youtube-session' => Http::response($sessionBody ?? ['id' => 'VIDEO-ABC-123', 'snippet' => ['title' => 'x']]),
        ]);
    }

    private function scheduleDuePublication(int $channelId, int $accountId, int $videoId): void
    {
        Publication::create([
            'video_id' => $videoId,
            'social_account_id' => $accountId,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);
    }

    public function test_cron_uploads_video_to_youtube_for_real(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        $this->fakeYouTube();
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertExitCode(0);

        $pub = Publication::first();
        $this->assertSame('published', $pub->status);
        $this->assertSame('https://www.youtube.com/watch?v=VIDEO-ABC-123', $pub->post_url);
        $this->assertSame('published', $video->fresh()->status);

        // The resumable session was initiated with title + description + tags,
        // public privacy, and always marked as not made for kids.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'upload/youtube/v3/videos')
                && str_contains($request->body(), '"title":"My Short"')
                && str_contains($request->body(), '"privacyStatus":"public"')
                && str_contains($request->body(), '"madeForKids":false');
        });

        // And the video bytes were streamed to the session URL.
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://upload.example.com/youtube-session'
                && $request->body() === 'fake-video-bytes';
        });
    }

    public function test_cron_uses_ai_metadata_for_the_real_upload(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        Setting::set('gemini.enabled', '1');
        $video->update([
            'analysis_status' => 'completed',
            'ai_title' => 'AI Generated Title',
            'ai_description' => 'AI description text',
            'ai_hashtags' => ['shorts', 'viral', 'hack', 'tips', 'test'],
        ]);
        $this->fakeYouTube();
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'upload/youtube/v3/videos')
                && str_contains($request->body(), '"title":"AI Generated Title"')
                && str_contains($request->body(), 'AI description text')
                && str_contains($request->body(), '"tags":["shorts","viral","hack","tips","test"]');
        });

        $pub = Publication::first();
        $this->assertSame('https://www.youtube.com/watch?v=VIDEO-ABC-123', $pub->post_url);
    }

    public function test_cron_invalid_grant_flags_account_and_requeues_post(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        $this->fakeYouTube(401, ['error' => ['errors' => [['reason' => 'invalid_grant']]]]);
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertFailed();

        $pub = Publication::first();
        $this->assertSame('failed', $pub->status);
        $this->assertTrue($pub->retry_on_reconnect);
        $this->assertSame('expired', $account->fresh()->status);
        $this->assertSame('scheduled', $video->fresh()->status);
    }

    public function test_cron_upload_failure_marks_publication_failed(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        $this->fakeYouTube(503, ['error' => ['message' => 'Backend Error']]);
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertFailed();

        $pub = Publication::first();
        $this->assertSame('failed', $pub->status);
        $this->assertFalse($pub->retry_on_reconnect);
        $this->assertSame('connected', $account->fresh()->status);
        $this->assertSame('scheduled', $video->fresh()->status);
    }

    public function test_cron_keeps_simulated_publish_without_credentials(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        $account->update([
            'google_client_id' => null,
            'google_client_secret' => null,
            'credentials' => [],
        ]);
        Http::fake();
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertExitCode(0);

        $pub = Publication::first();
        $this->assertSame('published', $pub->status);
        $this->assertStringStartsWith('https://youtube.com/shorts/', $pub->post_url);
        Http::assertNothingSent();
    }

    public function test_reupload_queues_fresh_scheduled_publication(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'scheduled_at' => now()->subDay(),
            'post_url' => 'https://youtube.com/shorts/old-fake',
        ]);

        // UI shows the reupload panel with the old published version.
        $show = $this->actingAs($user)->get(route('videos.show', $video));
        $show->assertOk();
        $show->assertSee('Reupload to YouTube', false);
        $show->assertSee('https://youtube.com/shorts/old-fake', false);

        $response = $this->actingAs($user)->post(route('videos.reupload', $video), [
            'account_id' => $account->id,
            'scheduled_at' => '2026-08-15T15:00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(2, Publication::where('video_id', $video->id)->count());
        $newPub = Publication::where('video_id', $video->id)->where('status', 'scheduled')->first();
        $this->assertNotNull($newPub);
        $this->assertSame('2026-08-15 15:00:00', $newPub->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $video->fresh()->status);
    }

    public function test_reupload_rejects_other_users_video(): void
    {
        [$user] = $this->createFixture();
        $otherUser = User::factory()->create();
        $otherChannel = Channel::create(['user_id' => $otherUser->id, 'name' => 'Other']);
        $otherVideo = Video::create(['channel_id' => $otherChannel->id, 'title' => 'Not Yours', 'status' => 'published']);

        $response = $this->actingAs($user)->post(route('videos.reupload', $otherVideo), [
            'account_id' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_manual_publish_now_uploads_for_real(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        $this->fakeYouTube();

        $response = $this->actingAs($user)->post(route('videos.publish', $video), [
            'account_id' => $account->id,
            'action_type' => 'publish_now',
            'custom_title' => 'Manual Publish Title',
            'custom_caption' => 'Manual caption #shorts',
        ]);

        $response->assertRedirect(route('videos.index'));
        $response->assertSessionHas('success', 'Video uploaded to your YouTube channel!');

        $pub = Publication::where('video_id', $video->id)->first();
        $this->assertSame('https://www.youtube.com/watch?v=VIDEO-ABC-123', $pub->post_url);
        $this->assertSame('Manual Publish Title', $pub->custom_title);
    }

    public function test_manual_publish_now_stays_simulated_without_credentials(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();
        $account->update([
            'google_client_id' => null,
            'google_client_secret' => null,
            'credentials' => [],
        ]);
        Http::fake();

        $response = $this->actingAs($user)->post(route('videos.publish', $video), [
            'account_id' => $account->id,
            'action_type' => 'publish_now',
        ]);

        $response->assertRedirect(route('videos.index'));
        $response->assertSessionHas('success', fn ($msg) => str_contains($msg, 'simulated'));

        $pub = Publication::where('video_id', $video->id)->first();
        $this->assertStringStartsWith('https://youtube.com/shorts/', $pub->post_url);
    }
}
