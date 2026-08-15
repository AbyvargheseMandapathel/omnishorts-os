<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessDuePublicationsTest extends TestCase
{
    use RefreshDatabase;

    private function createFixture(): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'My Short',
            'status' => 'scheduled',
        ]);
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Test Account',
        ]);

        return [$video, $account];
    }

    public function test_due_scheduled_publications_go_live(): void
    {
        [$video, $account] = $this->createFixture();

        $due = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'custom_title' => 'Go Live Now',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
        ]);

        $future = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'custom_title' => 'Not Yet',
            'status' => 'scheduled',
            'scheduled_at' => now()->addHours(3),
        ]);

        $this->artisan('publications:process-due')->assertSuccessful();

        $due->refresh();
        $future->refresh();

        $this->assertSame('published', $due->status);
        $this->assertNotNull($due->published_at);
        $this->assertNotNull($due->post_url);
        $this->assertNotNull($due->analytics);

        $this->assertSame('published', $video->refresh()->status);

        $this->assertSame('scheduled', $future->status);
        $this->assertNull($future->published_at);
    }

    public function test_future_publications_are_not_published(): void
    {
        [$video, $account] = $this->createFixture();

        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);

        $this->artisan('publications:process-due')->assertSuccessful();

        $this->assertSame('scheduled', $video->refresh()->status);
        $this->assertDatabaseHas('publications', ['status' => 'scheduled']);
        $this->assertDatabaseMissing('publications', ['status' => 'published']);
    }

    public function test_command_stamps_cron_last_checked(): void
    {
        [$video, $account] = $this->createFixture();

        $this->artisan('publications:process-due')->assertSuccessful();

        $stamp = Setting::get('cron.last_checked');
        $this->assertNotNull($stamp);
        $this->assertTrue(Carbon::parse($stamp)->greaterThanOrEqualTo(now()->subMinute()));
    }

    public function test_dashboard_shows_scheduler_never_ran(): void
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Never ran', false);
        $response->assertSee('Run Now', false);
    }

    public function test_dashboard_shows_scheduler_running_when_recent(): void
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        Setting::set('cron.last_checked', now()->subMinute()->toDateTimeString());

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Running', false);
        $response->assertSee('Last checked', false);
    }

    public function test_dashboard_shows_scheduler_stale_when_old(): void
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        Setting::set('cron.last_checked', now()->subHours(3)->toDateTimeString());

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Not running', false);
        $response->assertSee('cron:install', false);
    }

    public function test_dashboard_run_now_publishes_due_posts(): void
    {
        [$video, $account] = $this->createFixture();
        $user = User::find($video->channel->user_id);
        $publication = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($user)->post(route('dashboard.cron.run'));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('published', $publication->refresh()->status);
        $this->assertNotNull(Setting::get('cron.last_checked'));
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $events = collect(app('Illuminate\Console\Scheduling\Schedule')->events())
            ->filter(fn ($event) => str_contains($event->command, 'publications:process-due'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('* * * * *', $events->first()->expression);
    }

    public function test_invalid_grant_flags_account_for_reconnect(): void
    {
        [$video, $account] = $this->createFixture();
        $account->update(['credentials' => ['refresh_token' => 'dead-token']]);
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        $publication = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
        ]);

        $this->artisan('publications:process-due')->assertFailed();

        $this->assertSame('failed', $publication->refresh()->status);
        $this->assertTrue($publication->retry_on_reconnect);
        $this->assertSame('scheduled', $video->refresh()->status);
        $this->assertSame('expired', $account->refresh()->status);
    }

    public function test_valid_refresh_token_allows_publish(): void
    {
        [$video, $account] = $this->createFixture();
        $account->update(['credentials' => ['refresh_token' => 'healthy-token']]);
        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);
        Storage::fake('public');
        $video->update(['file_path' => 'videos/reel.mp4']);
        Storage::disk('public')->put('videos/reel.mp4', 'video-bytes');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
                'Location' => 'https://upload.example.com/session',
            ]),
            'upload.example.com/session' => Http::response(['id' => 'VIDEO-X']),
        ]);

        $publication = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
        ]);

        $this->artisan('publications:process-due')->assertSuccessful();

        $this->assertSame('published', $publication->refresh()->status);
        $this->assertStringContainsString('VIDEO-X', $publication->post_url);
        $this->assertSame('connected', $account->refresh()->status);
    }

    public function test_dashboard_shows_reconnect_banner_for_expired_account(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Tech Pulse',
            'status' => 'expired',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('expired', false);
        $response->assertSee('Tech Pulse', false);
        $response->assertSee('Reconnect', false);
        // The route URL's '&' is HTML-escaped inside the onclick attribute.
        $response->assertSee('/accounts/youtube/connect?account_id='.$account->id, false);
    }

    public function test_dashboard_shows_user_google_avatar(): void
    {
        $user = User::factory()->create(['avatar' => 'http://img/google-avatar.png']);
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('http://img/google-avatar.png', false);
    }

    public function test_reconnect_requeues_failed_publications(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Test Account',
            'status' => 'expired',
            'credentials' => ['refresh_token' => 'dead-token'],
        ]);
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'My Short',
            'status' => 'scheduled',
        ]);
        $failed = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'failed',
            'retry_on_reconnect' => true,
        ]);
        $other = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'failed',
            'retry_on_reconnect' => false,
        ]);

        session(['youtube_oauth' => [
            'access_token' => 'access-tok',
            'refresh_token' => 'refresh-tok',
            'channels' => [
                ['id' => 'UC-ONE', 'title' => 'Test Account', 'custom_url' => '@testaccount', 'thumbnail' => null, 'subscribers' => 100, 'video_count' => 5],
            ],
        ]]);

        $response = $this->actingAs($user)->post(route('accounts.youtube.select'), [
            'channel_id' => 'UC-ONE',
        ]);

        $response->assertRedirect(route('accounts.index'));
        $response->assertSessionHas('success', function ($message) {
            return str_contains($message, '1 queued post will auto-publish');
        });

        $this->assertSame('connected', $account->refresh()->status);
        $this->assertSame('scheduled', $failed->refresh()->status);
        $this->assertFalse($failed->retry_on_reconnect);
        // Non-retryable failures stay put.
        $this->assertSame('failed', $other->refresh()->status);
    }

    public function test_retried_post_publishes_after_reconnect(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Test Account',
            'status' => 'expired',
            'credentials' => ['refresh_token' => 'dead-token'],
        ]);
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'My Short',
            'status' => 'scheduled',
        ]);
        $failed = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'failed',
            'retry_on_reconnect' => true,
            'scheduled_at' => now()->subHour(),
        ]);

        config([
            'services.google.client_id' => 'client-123',
            'services.google.client_secret' => 'secret-123',
        ]);
        Storage::fake('public');
        $video->update(['file_path' => 'videos/reel.mp4']);
        Storage::disk('public')->put('videos/reel.mp4', 'video-bytes');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
            ]),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
                'Location' => 'https://upload.example.com/session',
            ]),
            'upload.example.com/session' => Http::response(['id' => 'VIDEO-X']),
        ]);

        // 1) Reconnect -> post is re-queued.
        session(['youtube_oauth' => [
            'access_token' => 'access-tok',
            'refresh_token' => 'refresh-tok',
            'channels' => [
                ['id' => 'UC-ONE', 'title' => 'Test Account', 'custom_url' => '@testaccount', 'thumbnail' => null, 'subscribers' => 100, 'video_count' => 5],
            ],
        ]]);
        $this->actingAs($user)->post(route('accounts.youtube.select'), ['channel_id' => 'UC-ONE']);

        $this->assertSame('scheduled', $failed->refresh()->status);

        // 2) Next scheduler tick publishes it.
        $this->artisan('publications:process-due')->assertSuccessful();

        $this->assertSame('published', $failed->refresh()->status);
        $this->assertSame('published', $video->refresh()->status);
    }

    private function createUserWithChannel(): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        return [$user, $channel];
    }
}
