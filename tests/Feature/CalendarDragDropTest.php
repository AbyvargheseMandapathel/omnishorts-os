<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarDragDropTest extends TestCase
{
    use RefreshDatabase;

    private function createFixture(array $schedule = ['posts_per_day' => 1, 'post_times' => ['18:00']]): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Test Channel',
        ], $schedule));
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Test YT',
            'status' => 'connected',
        ]);
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'My Reel',
            'status' => 'scheduled',
        ]);

        return [$user, $channel, $account, $video];
    }

    public function test_publication_can_be_moved_to_another_day_keeping_time(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();

        $pub = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-09-05 18:00:00'),
        ]);

        $response = $this->actingAs($user)->post(route('calendar.publication.move', $pub), [
            'date' => '2026-09-12',
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson(['success' => true]);

        $pub->refresh();
        $this->assertSame('2026-09-12 18:00:00', $pub->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $pub->status);
    }

    public function test_publication_cannot_be_moved_across_channels(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture();

        $otherUser = User::factory()->create();
        $otherChannel = Channel::create(['user_id' => $otherUser->id, 'name' => 'Other Channel']);

        $pub = Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($otherUser)->post(route('calendar.publication.move', $pub), [
            'date' => '2026-09-20',
        ]);

        $response->assertForbidden();
    }

    public function test_ready_reel_can_be_dropped_into_first_free_slot(): void
    {
        [$user, $channel, $account, $video] = $this->createFixture([
            'posts_per_day' => 2,
            'post_times' => ['12:00', '18:00'],
        ]);

        $ready = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Ready Reel',
            'status' => 'ready',
        ]);

        // 12:00 already occupied on the target day
        $otherVideo = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Occupied Slot',
            'status' => 'scheduled',
        ]);
        Publication::create([
            'video_id' => $otherVideo->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-09-10 12:00:00'),
        ]);

        $response = $this->actingAs($user)->post(route('calendar.schedule'), [
            'video_id' => $ready->id,
            'date' => '2026-09-10',
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson(['success' => true]);

        $pub = Publication::where('video_id', $ready->id)->first();
        $this->assertNotNull($pub);
        $this->assertSame('2026-09-10 18:00:00', $pub->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $ready->refresh()->status);
    }

    public function test_dropping_video_from_other_channel_is_rejected(): void
    {
        [$user] = $this->createFixture();

        $otherUser = User::factory()->create();
        $otherChannel = Channel::create(['user_id' => $otherUser->id, 'name' => 'Other']);
        $otherVideo = Video::create([
            'channel_id' => $otherChannel->id,
            'title' => 'Not Yours',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($user)->post(route('calendar.schedule'), [
            'video_id' => $otherVideo->id,
            'date' => '2026-09-10',
        ]);

        $response->assertNotFound();
    }

    public function test_dropping_without_youtube_account_returns_422(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Ready Reel',
            'status' => 'ready',
        ]);

        $response = $this->actingAs($user)->post(route('calendar.schedule'), [
            'video_id' => $video->id,
            'date' => '2026-09-10',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $this->assertDatabaseCount('publications', 0);
    }
}
