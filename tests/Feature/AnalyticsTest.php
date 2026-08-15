<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\PublicationMetric;
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
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [['id' => 'UC-TEST-CHANNEL', 'statistics' => ['subscriberCount' => '12345']]],
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

    public function test_refresh_account_updates_follower_count(): void
    {
        [, , $account] = $this->createPublishedFixture();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [['id' => 'UC-TEST-CHANNEL', 'statistics' => ['subscriberCount' => '3210']]],
            ]),
        ]);

        $this->assertTrue(app(YouTubeAnalytics::class)->refreshAccount($account));
        $this->assertSame(3210, $account->fresh()->follower_count);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/youtube/v3/channels'));
    }

    public function test_refresh_account_skips_without_credentials(): void
    {
        [, , $account] = $this->createPublishedFixture();
        $account->update(['google_client_id' => null, 'google_client_secret' => null, 'follower_count' => 999]);
        Http::fake();

        $this->assertFalse(app(YouTubeAnalytics::class)->refreshAccount($account));
        $this->assertSame(999, $account->fresh()->follower_count);
        Http::assertNothingSent();
    }

    public function test_refresh_account_never_zeros_out_undisclosed_subscribers(): void
    {
        [, , $account] = $this->createPublishedFixture();
        $account->update(['follower_count' => 777]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [['id' => 'UC-TEST-CHANNEL', 'statistics' => []]],
            ]),
        ]);

        $this->assertFalse(app(YouTubeAnalytics::class)->refreshAccount($account));
        $this->assertSame(777, $account->fresh()->follower_count);
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

        // Subscriber counts are refreshed by the same command.
        $this->assertSame(12345, $account->fresh()->follower_count);
    }

    public function test_dashboard_refresh_all_runs_analytics_command(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $this->fakeTokenAndStats();

        $response = $this->actingAs($user)->post(route('dashboard.analytics.refresh'));

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $this->assertSame(1200, $publication->fresh()->analytics['views']);
        // Subscriber counts refresh too (channels.list).
        $this->assertSame(12345, $account->fresh()->follower_count);
    }

    public function test_analytics_refresh_is_scheduled_twice_daily(): void
    {
        $events = collect(app('Illuminate\Console\Scheduling\Schedule')->events())
            ->filter(fn ($event) => str_contains($event->command, 'analytics:refresh'));

        $this->assertCount(1, $events);
        // Twice daily (08:00 & 20:00) — not hourly — to keep YouTube API hits low.
        $this->assertSame('0 8,20 * * *', $events->first()->expression);
    }

    public function test_video_page_shows_real_stats_and_views_curve(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $publication->update(['analytics' => ['views' => 9000, 'likes' => 700, 'comments' => 100, 'shares' => 40]]);
        PublicationMetric::create([
            'publication_id' => $publication->id,
            'views' => 4000,
            'likes' => 300,
            'comments' => 40,
            'shares' => 10,
            'fetched_at' => now()->subDays(2),
        ]);
        PublicationMetric::create([
            'publication_id' => $publication->id,
            'views' => 9000,
            'likes' => 700,
            'comments' => 100,
            'shares' => 40,
            'fetched_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('videos.show', $video));

        $response->assertOk();
        // Real totals across the reel's published versions.
        $response->assertSee('9,000', false);
        $response->assertSee('Performance', false);
        // Growth curve sparkline rendered from the metric snapshots.
        $response->assertSee('video-views-curve', false);
        $response->assertSee('peak', false);
        // Last-refresh timestamp is shown on the card.
        $response->assertSee('last refreshed', false);
    }

    public function test_video_page_excludes_simulated_versions_from_totals(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $publication->update(['analytics' => ['views' => 9000, 'likes' => 700, 'comments' => 100, 'shares' => 40]]);
        // A simulated (non-watch) version with fabricated numbers must not count.
        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'post_url' => 'https://youtube.com/shorts/simulated-xyz',
            'published_at' => now()->subHour(),
            'analytics' => ['views' => 999999, 'likes' => 999, 'comments' => 99, 'shares' => 9],
        ]);

        $response = $this->actingAs($user)->get(route('videos.show', $video));

        $response->assertOk();
        $response->assertSee('9,000', false);
        $response->assertDontSee('999,999', false);
    }

    public function test_video_page_marks_stats_never_refreshed(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $publication->update(['analytics' => ['views' => 500, 'likes' => 10, 'comments' => 2, 'shares' => 0]]);

        $response = $this->actingAs($user)->get(route('videos.show', $video));

        $response->assertOk();
        // Totals render (real data), but no snapshot history -> never refreshed.
        $response->assertSee('500', false);
        $response->assertSee('not refreshed yet', false);
    }

    public function test_refresh_stats_button_fetches_fresh_data_for_reel(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $this->fakeTokenAndStats();

        $response = $this->actingAs($user)->post(route('videos.refresh-stats', $video));

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $this->assertSame(1200, $publication->fresh()->analytics['views']);
        $this->assertDatabaseHas('publication_metrics', [
            'publication_id' => $publication->id,
            'views' => 1200,
        ]);
    }

    public function test_refresh_stats_reports_error_without_real_urls(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $publication->update(['post_url' => 'https://youtube.com/shorts/simulated-abc']);
        Http::fake();

        $response = $this->actingAs($user)->post(route('videos.refresh-stats', $video));

        $response->assertSessionHasErrors('stats');
        Http::assertNothingSent();
        $this->assertDatabaseCount('publication_metrics', 0);
    }

    public function test_refresh_stats_blocks_other_users_video(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();
        $other = User::factory()->create();
        $otherChannel = Channel::create(['user_id' => $other->id, 'name' => 'Other Channel']);

        $response = $this->actingAs($other)
            ->withSession(['active_channel_id' => $otherChannel->id])
            ->post(route('videos.refresh-stats', $video));

        $response->assertForbidden();
    }

    public function test_video_page_shows_empty_state_without_real_stats(): void
    {
        [$user, $channel, $account, $video, $publication] = $this->createPublishedFixture();

        $response = $this->actingAs($user)->get(route('videos.show', $video));

        $response->assertOk();
        $response->assertSee('No real stats yet', false);
        $response->assertDontSee('video-views-curve', false);
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

        // Simulated publish with fabricated stats — must not appear in totals
        // or the best-performer ranking.
        Publication::create([
            'video_id' => $weakVideo->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'post_url' => 'https://youtube.com/shorts/simulated-fake',
            'published_at' => now()->subHours(1),
            'analytics' => ['views' => 999999, 'likes' => 50000, 'comments' => 2000, 'shares' => 1000],
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Performance', false);
        // Real totals from analytics, not fabricated numbers.
        $response->assertSee('9,100', false);
        $response->assertDontSee('999,999', false);
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
