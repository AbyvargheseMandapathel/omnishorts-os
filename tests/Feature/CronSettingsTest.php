<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class CronSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function scheduleEvents(): Collection
    {
        return collect(app('Illuminate\Console\Scheduling\Schedule')->events());
    }

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

    public function test_settings_save_stores_cron_toggles(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();

        $this->actingAs($user)->post(route('settings.cron.save'), [
            'enabled' => '0',
            'publish_enabled' => '0',
            'analytics_enabled' => '1',
            'prune_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('0', Setting::get('cron.enabled'));
        $this->assertSame('0', Setting::get('cron.publish_enabled'));
        $this->assertSame('1', Setting::get('cron.analytics_enabled'));
        $this->assertSame('1', Setting::get('cron.prune_enabled'));

        // Defaults are on before anything is saved.
        Setting::forget('cron.enabled');
        $this->assertSame('1', Setting::get('cron.enabled', '1'));
    }

    public function test_settings_page_shows_cron_card_and_manual_line(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();

        $response = $this->actingAs($user)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee('Scheduler &amp; Cron Jobs', false);
        $response->assertSee('Install / Sync Cron', false);
        $response->assertSee('artisan schedule:run', false);
    }

    public function test_schedule_guards_respect_cron_settings(): void
    {
        $publish = $this->scheduleEvents()
            ->first(fn ($event) => str_contains((string) $event->command, 'publications:process-due'));

        $this->assertNotNull($publish);

        Setting::set('cron.enabled', '1');
        Setting::set('cron.publish_enabled', '1');
        $this->assertTrue($publish->filtersPass(app()));

        Setting::set('cron.enabled', '0');
        $this->assertFalse($publish->filtersPass(app()));

        Setting::set('cron.enabled', '1');
        Setting::set('cron.publish_enabled', '0');
        $this->assertFalse($publish->filtersPass(app()));

        // The heartbeat always runs (closure event, every minute) so the
        // dashboard knows the scheduler is alive regardless of job toggles.
        $heartbeat = $this->scheduleEvents()
            ->first(fn ($event) => $event instanceof CallbackEvent);
        $this->assertNotNull($heartbeat);
        $this->assertSame('* * * * *', $heartbeat->getExpression());
    }

    public function test_disabling_cron_does_not_block_manual_run(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        Setting::set('cron.enabled', '0');
        Setting::set('cron.publish_enabled', '0');

        $video = Video::create([
            'channel_id' => $channel->id,
            'title' => 'Manual Reel',
            'status' => 'ready',
        ]);
        Publication::create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('publications:process-due')->assertExitCode(0);

        // Manual/CLI runs are unaffected — the disable only gates the scheduler.
        $this->assertSame('published', Publication::first()->status);
    }

    public function test_dashboard_shows_disabled_state_when_cron_paused(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        Setting::set('cron.enabled', '0');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('○ Disabled', false);
        $response->assertSee('Auto-publishing is paused', false);
    }

    public function test_dashboard_shows_running_when_cron_enabled_and_heartbeat_fresh(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        Setting::set('cron.enabled', '1');
        Setting::set('cron.last_checked', now()->subMinute()->toDateTimeString());

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('● Running', false);
    }
}
