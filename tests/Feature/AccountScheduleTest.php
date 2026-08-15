<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithChannel(array $schedule = ['posts_per_day' => 1, 'post_times' => ['18:00']]): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Test Channel',
        ], $schedule));

        return [$user, $channel];
    }

    private function createYoutubeAccount(int $channelId, array $own = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'channel_id' => $channelId,
            'platform' => 'youtube',
            'account_name' => 'AnimeWorld Daily',
            'handle' => '@animeworld_daily',
            'status' => 'connected',
        ], $own));
    }

    public function test_account_schedule_can_be_updated(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = $this->createYoutubeAccount($channel->id);

        $response = $this->actingAs($user)->put(route('accounts.schedule.update', $account), [
            'posts_per_day' => 3,
            'post_times' => ['09:00', '13:30', '20:15'],
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $account->refresh();
        $this->assertSame(3, $account->posts_per_day);
        $this->assertSame(['09:00', '13:30', '20:15'], $account->post_times);
        $this->assertSame(['09:00', '13:30', '20:15'], $account->postingTimes());
        $this->assertTrue($account->hasOwnSchedule());
    }

    public function test_account_schedule_overrides_channel_default(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = $this->createYoutubeAccount($channel->id, [
            'posts_per_day' => 2,
            'post_times' => ['07:15', '21:45'],
        ]);

        $this->assertSame(2, $account->postsPerDay());
        $this->assertSame(['07:15', '21:45'], $account->postingTimes());
        $this->assertSame('2 posts/day at 07:15 AM & 09:45 PM', $account->scheduleLabel());
    }

    public function test_account_falls_back_to_channel_schedule(): void
    {
        [$user, $channel] = $this->createUserWithChannel([
            'posts_per_day' => 2,
            'post_times' => ['12:00', '18:00'],
        ]);
        $account = $this->createYoutubeAccount($channel->id);

        $this->assertSame(2, $account->postsPerDay());
        $this->assertSame(['12:00', '18:00'], $account->postingTimes());
        $this->assertFalse($account->hasOwnSchedule());
    }

    public function test_account_can_reset_to_channel_default(): void
    {
        [$user, $channel] = $this->createUserWithChannel([
            'posts_per_day' => 1,
            'post_times' => ['18:00'],
        ]);
        $account = $this->createYoutubeAccount($channel->id, [
            'posts_per_day' => 4,
            'post_times' => ['06:00', '10:00', '14:00', '18:00'],
        ]);

        $response = $this->actingAs($user)->put(route('accounts.schedule.update', $account), [
            'use_channel_default' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $account->refresh();
        $this->assertNull($account->posts_per_day);
        $this->assertNull($account->post_times);
        $this->assertFalse($account->hasOwnSchedule());
        $this->assertSame(['18:00'], $account->postingTimes());
    }

    public function test_bulk_upload_uses_account_cron_not_channel(): void
    {
        // Channel says 1 post/day at 18:00; the account overrides with 2/day.
        [$user, $channel] = $this->createUserWithChannel([
            'posts_per_day' => 1,
            'post_times' => ['18:00'],
        ]);
        $account = $this->createYoutubeAccount($channel->id, [
            'posts_per_day' => 2,
            'post_times' => ['12:00', '18:00'],
        ]);
        Storage::fake('public');

        $files = [];
        for ($i = 1; $i <= 3; $i++) {
            $files[] = UploadedFile::fake()->create("reel_{$i}.mp4", 100, 'video/mp4');
        }

        $this->actingAs($user)->post(route('videos.bulk.store'), [
            'videos' => $files,
            'start_date' => '2026-09-01',
            'youtube_account_id' => $account->id,
        ]);

        $slots = Publication::orderBy('scheduled_at')->get()->map(fn ($p) => $p->scheduled_at->format('Y-m-d H:i'))->all();
        $this->assertSame(['2026-09-01 12:00', '2026-09-01 18:00', '2026-09-02 12:00'], $slots);
    }

    public function test_publish_uses_account_next_free_slot(): void
    {
        [$user, $channel] = $this->createUserWithChannel([
            'posts_per_day' => 1,
            'post_times' => ['18:00'],
        ]);
        $account = $this->createYoutubeAccount($channel->id, [
            'posts_per_day' => 1,
            'post_times' => ['09:00'],
        ]);
        $video = Video::create(['channel_id' => $channel->id, 'title' => 'My Reel', 'status' => 'ready']);

        $this->actingAs($user)->post(route('videos.publish', $video), [
            'account_id' => $account->id,
            'action_type' => 'schedule',
        ]);

        $pub = Publication::where('video_id', $video->id)->first();
        $this->assertNotNull($pub);
        // Next free slot follows the account's 09:00 cron, not the channel's 18:00.
        $this->assertSame('09:00', $pub->scheduled_at->format('H:i'));
    }

    public function test_calendar_schedule_uses_account_cron(): void
    {
        [$user, $channel] = $this->createUserWithChannel([
            'posts_per_day' => 1,
            'post_times' => ['18:00'],
        ]);
        $account = $this->createYoutubeAccount($channel->id, [
            'posts_per_day' => 2,
            'post_times' => ['09:00', '17:00'],
        ]);
        $video = Video::create(['channel_id' => $channel->id, 'title' => 'Ready Reel', 'status' => 'ready']);

        $response = $this->actingAs($user)->post(route('calendar.schedule'), [
            'video_id' => $video->id,
            'date' => '2026-09-10',
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson(['success' => true]);

        $pub = Publication::where('video_id', $video->id)->first();
        $this->assertSame('2026-09-10 09:00:00', $pub->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_next_free_slot_is_scoped_per_account(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $accountA = $this->createYoutubeAccount($channel->id, [
            'posts_per_day' => 1,
            'post_times' => ['12:00'],
        ]);
        $accountB = $this->createYoutubeAccount($channel->id, [
            'posts_per_day' => 1,
            'post_times' => ['12:00'],
        ]);

        $video = Video::create(['channel_id' => $channel->id, 'title' => 'Taken', 'status' => 'scheduled']);
        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $accountA->id,
            'status' => 'scheduled',
            'scheduled_at' => Carbon::parse('2026-09-01 12:00:00'),
        ]);

        // Account A's 12:00 slot is taken; account B still has it free.
        $this->assertSame('2026-09-02 12:00', $accountA->nextFreeSlot(Carbon::parse('2026-09-01 00:00:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-09-01 12:00', $accountB->nextFreeSlot(Carbon::parse('2026-09-01 00:00:00'))->format('Y-m-d H:i'));
    }

    public function test_update_schedule_rejects_other_users_account(): void
    {
        [$user] = $this->createUserWithChannel();
        $otherUser = User::factory()->create();
        $otherChannel = Channel::create(['user_id' => $otherUser->id, 'name' => 'Foreign']);
        $foreignAccount = $this->createYoutubeAccount($otherChannel->id);

        $response = $this->actingAs($user)->put(route('accounts.schedule.update', $foreignAccount), [
            'posts_per_day' => 2,
            'post_times' => ['09:00', '18:00'],
        ]);

        $response->assertForbidden();
        $foreignAccount->refresh();
        $this->assertNull($foreignAccount->posts_per_day);
    }

    public function test_update_schedule_validates_times(): void
    {
        [$user, $channel] = $this->createUserWithChannel();
        $account = $this->createYoutubeAccount($channel->id);

        $response = $this->actingAs($user)->put(route('accounts.schedule.update', $account), [
            'posts_per_day' => 0,
            'post_times' => ['not-a-time'],
        ]);

        $response->assertSessionHasErrors(['posts_per_day', 'post_times.0']);
    }
}
