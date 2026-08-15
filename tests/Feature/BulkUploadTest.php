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

class BulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithChannelAndYoutube(array $schedule = []): array
    {
        $user = User::factory()->create();
        $channel = Channel::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Test Channel',
            'category' => 'tech',
            'posts_per_day' => 1,
            'post_times' => ['18:00'],
        ], $schedule));
        $account = SocialAccount::create([
            'channel_id' => $channel->id,
            'platform' => 'youtube',
            'account_name' => 'Test YT',
            'handle' => '@testyt',
            'status' => 'connected',
        ]);

        return [$user, $channel, $account];
    }

    private function fakeVideos(int $count): array
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = UploadedFile::fake()->create("reel_{$i}.mp4", 100, 'video/mp4');
        }

        return $files;
    }

    public function test_bulk_upload_follows_channel_schedule_two_posts_per_day(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube([
            'posts_per_day' => 2,
            'post_times' => ['12:00', '18:00'],
        ]);
        Storage::fake('public');

        $response = $this->actingAs($user)->post(route('videos.bulk.store'), [
            'videos' => $this->fakeVideos(5),
            'start_date' => '2026-09-01',
            'youtube_account_id' => $account->id,
        ]);

        $response->assertRedirect(route('calendar.index', ['date' => '2026-09-01']));

        $this->assertDatabaseCount('videos', 5);
        $this->assertDatabaseCount('publications', 5);
        $this->assertSame('scheduled', Video::first()->status);

        $publications = Publication::with('video')->orderBy('scheduled_at')->get();
        $this->assertSame('scheduled', $publications[0]->status);

        // Two posts on day one, two on day two, one on day three
        $expectedSlots = [
            '2026-09-01 12:00:00',
            '2026-09-01 18:00:00',
            '2026-09-02 12:00:00',
            '2026-09-02 18:00:00',
            '2026-09-03 12:00:00',
        ];

        foreach ($publications as $i => $pub) {
            $this->assertSame($expectedSlots[$i], $pub->scheduled_at->format('Y-m-d H:i:s'), "slot #{$i}");
            $this->assertSame($account->id, $pub->social_account_id);
        }

        $this->assertSame('Reel 1', $publications[0]->video->title);
        Storage::disk('public')->assertExists($publications[0]->video->file_path);
    }

    public function test_bulk_upload_uses_custom_channel_times(): void
    {
        [$user, $account] = $this->createUserWithChannelAndYoutube([
            'posts_per_day' => 2,
            'post_times' => ['07:15', '21:45'],
        ]);
        Storage::fake('public');

        $this->actingAs($user)->post(route('videos.bulk.store'), [
            'videos' => $this->fakeVideos(3),
            'start_date' => '2026-09-10',
        ]);

        $publications = Publication::orderBy('scheduled_at')->get();

        $expectedSlots = [
            '2026-09-10 07:15:00',
            '2026-09-10 21:45:00',
            '2026-09-11 07:15:00',
        ];

        foreach ($publications as $i => $pub) {
            $this->assertSame($expectedSlots[$i], $pub->scheduled_at->format('Y-m-d H:i:s'), "slot #{$i}");
        }
    }

    public function test_bulk_upload_queues_one_post_per_day(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');

        $this->actingAs($user)->post(route('videos.bulk.store'), [
            'videos' => $this->fakeVideos(3),
            'start_date' => '2026-09-10',
        ]);

        $publications = Publication::orderBy('scheduled_at')->get();

        $expectedSlots = [
            '2026-09-10 18:00:00',
            '2026-09-11 18:00:00',
            '2026-09-12 18:00:00',
        ];

        foreach ($publications as $i => $pub) {
            $this->assertSame($expectedSlots[$i], $pub->scheduled_at->format('Y-m-d H:i:s'), "slot #{$i}");
        }
    }

    public function test_bulk_upload_requires_connected_youtube_account(): void
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);
        Storage::fake('public');

        $response = $this->actingAs($user)->post(route('videos.bulk.store'), [
            'videos' => $this->fakeVideos(2),
            'start_date' => Carbon::tomorrow()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('youtube_account_id');
        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('publications', 0);
    }

    public function test_bulk_upload_uses_tomorrow_when_no_start_date(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        Storage::fake('public');

        $this->actingAs($user)->post(route('videos.bulk.store'), [
            'videos' => $this->fakeVideos(1),
        ]);

        $pub = Publication::first();
        $this->assertSame(
            Carbon::tomorrow()->format('Y-m-d'),
            $pub->scheduled_at->format('Y-m-d')
        );
        $this->assertSame('18:00:00', $pub->scheduled_at->format('H:i:s'));
    }
}
