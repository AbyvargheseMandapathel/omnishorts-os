<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Services\YouTubeAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function createPublishedFixture(array $credentials = []): array
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
            'credentials' => array_merge([
                'refresh_token' => 'healthy-refresh-token',
                'youtube_channel_id' => 'UC-TEST-CHANNEL',
            ], $credentials),
        ]);
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'My Reel',
            'status' => 'published',
        ]);
        $publication = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'post_url' => 'https://www.youtube.com/watch?v=VID-111',
            'published_at' => now()->subHour(),
        ]);

        return [$user, $channel, $account, $video, $publication];
    }

    private function fakeTokenAndStats(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
            ]),
            'youtubeanalytics.googleapis.com/v2/reports*' => Http::response([
                'rows' => [[1200, 150, 30, 12]],
            ]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [['id' => 'VID-111', 'statistics' => ['viewCount' => '1200', 'likeCount' => '150', 'commentCount' => '30']]],
            ]),
        ]);
    }

    public function test_refresh_fetches_analytics_report_with_shares(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $this->fakeTokenAndStats();

        $stats = app(YouTubeAnalytics::class)->refresh($publication);

        $this->assertSame(['views' => 1200, 'likes' => 150, 'comments' => 30, 'shares' => 12], $stats);
        $this->assertSame(1200, $publication->fresh()->analytics['views']);
        $this->assertDatabaseHas('publication_metrics', [
            'publication_id' => $publication->id,
            'views' => 1200,
            'likes' => 150,
            'comments' => 30,
            'shares' => 12,
        ]);

        // Shares only come from the Analytics API report, not videos.list.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'youtubeanalytics.googleapis.com'));
    }

    public function test_refresh_falls_back_to_videos_list_without_channel_id(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture([
            'youtube_channel_id' => null,
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [['id' => 'VID-111', 'statistics' => ['viewCount' => '500', 'likeCount' => '10', 'commentCount' => '2']]],
            ]),
        ]);

        $stats = app(YouTubeAnalytics::class)->refresh($publication);

        $this->assertSame(['views' => 500, 'likes' => 10, 'comments' => 2, 'shares' => 0], $stats);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'youtubeanalytics.googleapis.com'));
    }

    public function test_refresh_skips_without_real_credentials(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $account->update(['google_client_id' => null, 'google_client_secret' => null]);
        Http::fake();

        $this->assertNull(app(YouTubeAnalytics::class)->refresh($publication));
        Http::assertNothingSent();
        $this->assertDatabaseCount('publication_metrics', 0);
    }

    public function test_refresh_skips_simulated_posts(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $publication->update(['post_url' => 'https://youtube.com/shorts/fake-abc123']);
        Http::fake();

        $this->assertNull(app(YouTubeAnalytics::class)->refresh($publication));
        Http::assertNothingSent();
    }

    public function test_analytics_refresh_command_updates_published_reels(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $this->fakeTokenAndStats();

        $this->artisan('analytics:refresh')->assertSuccessful();

        $this->assertSame(1200, $publication->fresh()->analytics['views']);
        $this->assertDatabaseHas('publication_metrics', ['publication_id' => $publication->id, 'views' => 1200]);
    }

    public function test_analytics_refresh_is_scheduled_hourly(): void
    {
        $events = collect(app('Illuminate\Console\Scheduling\Schedule')->events())
            ->filter(fn ($event) => str_contains($event->command, 'analytics:refresh'));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('0 * * * *', $events->first()->expression);
    }

    public function test_dashboard_shows_real_stats_and_best_performers(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $publication->update(['analytics' => ['views' => 9000, 'likes' => 700, 'comments' => 100, 'shares' => 40]]);

        $weakVideo = Video::create(['channel_id' => $channel->id, 'title' => 'Weak Reel', 'status' => 'published']);
        $weakPub = Publication::create([
            'video_id' => $weakVideo->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'post_url' => 'https://www.youtube.com/watch?v=VID-222',
            'published_at' => now()->subHours(2),
            'analytics' => ['views' => 100, 'likes' => 5, 'comments' => 1, 'shares' => 0],
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Performance', false);
        // Real totals from analytics, not fabricated numbers.
        $response->assertSee('9,100', false);
        $response->assertSee('likes', false);
        $response->assertSee('comments', false);
        $response->assertSee('shares', false);

        // Best performer ranks first with its real view badge.
        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'Weak Reel'),
            strpos($html, 'My Reel'),
            'Best-performing reel should appear above weaker ones'
        );
        $this->assertStringContainsString('9,000', $html);
        $this->assertSame(100, $weakPub->fresh()->analytics['views']);
    }
}
