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

class ChannelScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithChannel(): array
    {
        $user = User::factory()->create();
        $channel = Channel::create([
            'user_id' => $user->id,
            'name' => 'Test Channel',
        ]);

        return [$user, $channel];
    }

    public function test_channel_schedule_can_be_updated(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        $response = $this->actingAs($user)->put(route('channels.schedule.update', $channel), [
            'posts_per_day' => 3,
            'post_times' => ['09:00', '13:30', '20:15'],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $channel->refresh();
        $this->assertSame(3, $channel->posts_per_day);
        $this->assertSame(['09:00', '13:30', '20:15'], $channel->post_times);
        $this->assertSame(['09:00', '13:30', '20:15'], $channel->postingTimes());
    }

    public function test_schedule_keeps_only_posts_per_day_times(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        $this->actingAs($user)->put(route('channels.schedule.update', $channel), [
            'posts_per_day' => 2,
            'post_times' => ['08:00', '12:00', '16:00', '20:00'],
        ]);

        $channel->refresh();
        $this->assertSame(2, $channel->posts_per_day);
        $this->assertSame(['08:00', '12:00'], $channel->postingTimes());
    }

    public function test_schedule_validates_times_and_count(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        $response = $this->actingAs($user)->put(route('channels.schedule.update', $channel), [
            'posts_per_day' => 0,
            'post_times' => ['not-a-time'],
        ]);

        $response->assertSessionHasErrors(['posts_per_day', 'post_times.0']);
    }

    public function test_posting_times_fallback_to_18_00(): void
    {
        [$user, $channel] = $this->createUserWithChannel();

        $this->assertSame(['18:00'], $channel->postingTimes());
    }

    public function test_next_free_slot_skips_occupied_slots(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $channel->update(['posts_per_day' => 2, 'post_times' => ['12:00', '18:00']]);

        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'YT',
            'status' => 'connected',
        ]);
        $video = Video::create(['channel_id' => $channel->id, 'title' => 'Taken', 'status' => 'scheduled']);
        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-09-01 12:00:00'),
        ]);

        $slot = $channel->nextFreeSlot(Carbon::parse('2026-09-01 00:00:00'));

        $this->assertSame('2026-09-01 18:00', $slot->format('Y-m-d H:i'));
    }

    public function test_next_free_slot_returns_first_slot_when_free(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $channel->update(['posts_per_day' => 2, 'post_times' => ['12:00', '18:00']]);

        $slot = $channel->nextFreeSlot(Carbon::parse('2026-09-01 00:00:00'));

        $this->assertSame('2026-09-01 12:00', $slot->format('Y-m-d H:i'));
    }

    public function test_schedule_publish_defaults_to_next_cron_slot(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $channel->update(['posts_per_day' => 1, 'post_times' => ['18:00']]);

        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'YT',
            'status' => 'connected',
        ]);
        $video = Video::create(['channel_id' => $channel->id, 'title' => 'My Reel', 'status' => 'ready']);

        $expectedSlot = $channel->nextFreeSlot();

        $response = $this->actingAs($user)->post(route('videos.publish', $video), [
            'account_id' => $account->id,
            'action_type' => 'schedule',
        ]);

        $response->assertRedirect(route('videos.index'));

        $pub = Publication::where('video_id', $video->id)->first();
        $this->assertNotNull($pub);
        $this->assertSame($expectedSlot->format('Y-m-d H:i'), $pub->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('scheduled', $pub->status);
    }
}
