<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Publication;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Services\GeminiVideoAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private const ANALYSIS = [
        'hook' => 'This one trick changed everything',
        'title' => 'The 3AM Hack Nobody Told You About',
        'description' => 'Quick breakdown of the workflow that saved me hours.',
        'hashtags' => ['shorts', 'hack', 'productivity', 'viral', 'tips'],
        'thumbnail_text' => 'STOP scrolling',
        'best_moment' => ['start' => '0:12', 'end' => '0:18', 'reason' => 'climax of the reveal'],
        'category' => 'Education',
        'target_audience' => 'Young creators looking for productivity hacks',
        'virality_score' => 92,
        'improvement' => 'Add a faster cut at the 3-second mark.',
    ];

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

    private function createVideoWithFile(int $channelId, string $title = 'My Reel'): Video
    {
        Storage::fake('public');
        $video = Video::create([
            'channel_id' => $channelId,
            'title' => $title,
            'description' => 'Original description',
            'file_path' => 'videos/reel.mp4',
            'duration' => 30,
            'status' => 'ready',
        ]);
        Storage::disk('public')->put($video->file_path, 'fake-video-bytes');

        return $video;
    }

    private function fakeGemini(?string $rawText = null, int $status = 200): void
    {
        if ($rawText === null) {
            $rawText = json_encode(self::ANALYSIS);
        }

        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files' => Http::response('', 200, [
                'X-Goog-Upload-URL' => 'https://upload.example.com/session',
            ]),
            'upload.example.com/*' => Http::response(['file' => ['uri' => 'files/video-1', 'state' => 'ACTIVE']]),
            'generativelanguage.googleapis.com/v1beta/models/*:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $rawText]]]]],
            ], $status),
            'generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'models/gemini-1.5-flash']),
        ]);
    }

    private function enableGemini(string $key = 'AIza-test-key'): void
    {
        Setting::set('gemini.enabled', '1');
        Setting::set('gemini.model', 'gemini-1.5-flash');
        Setting::set('gemini.api_key', Crypt::encryptString($key));
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

    public function test_settings_save_stores_key_encrypted_and_page_masks_it(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();

        $this->actingAs($user)->post(route('settings.gemini.save'), [
            'enabled' => '1',
            'model' => 'gemini-1.5-flash',
            'api_key' => 'AIza-super-secret-key',
        ])->assertSessionHasNoErrors();

        $raw = Setting::get('gemini.api_key');
        $this->assertNotSame('AIza-super-secret-key', $raw);
        $this->assertSame('AIza-super-secret-key', Crypt::decryptString($raw));
        $this->assertSame('1', Setting::get('gemini.enabled'));
        $this->assertSame('gemini-1.5-flash', Setting::get('gemini.model'));

        $response = $this->actingAs($user)->get(route('settings.index'));
        $response->assertOk();
        $response->assertSee('••• saved •••', false);
        $response->assertDontSee('AIza-super-secret-key');
    }

    public function test_custom_gemini_model_name_is_saved_and_used(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        Setting::set('gemini.enabled', '1');
        Setting::set('gemini.api_key', Crypt::encryptString('test-key'));

        $this->actingAs($user)->post(route('settings.gemini.save'), [
            'enabled' => '1',
            'model' => 'gemini-2.5-flash-exp-08-27',
            'api_key' => 'AIza-custom',
        ])->assertSessionHasNoErrors();

        $this->assertSame('gemini-2.5-flash-exp-08-27', Setting::get('gemini.model'));
        $this->assertSame('gemini-2.5-flash-exp-08-27', app(GeminiVideoAnalyzer::class)->model());

        // Manual analysis must call Gemini with the custom model name.
        $this->fakeGemini();
        $video = $this->createVideoWithFile($channel->id);
        $this->actingAs($user)->post(route('videos.analyze', $video));

        $video->refresh();
        $this->assertSame('gemini-2.5-flash-exp-08-27', $video->model_used);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'models/gemini-2.5-flash-exp-08-27:generateContent'));
    }

    public function test_settings_can_remove_saved_key(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();

        $this->actingAs($user)->post(route('settings.gemini.save'), [
            'enabled' => '1',
            'model' => 'gemini-1.5-flash',
            'remove_api_key' => '1',
        ]);

        $this->assertNull(Setting::get('gemini.api_key'));
    }

    public function test_settings_test_connection_success(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'models/gemini-1.5-flash'])]);

        $response = $this->actingAs($user)->post(route('settings.gemini.test'));

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('gemini-1.5-flash', $response->json('message'));
    }

    public function test_settings_test_connection_reports_bad_key(): void
    {
        [$user] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/*' => Http::response([], 401)]);

        $response = $this->actingAs($user)->post(route('settings.gemini.test'));

        $response->assertOk()->assertJson(['ok' => false]);
        $this->assertStringContainsString('API key', $response->json('message'));
    }

    public function test_manual_analyze_completes_and_updates_metadata(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini();
        $video = $this->createVideoWithFile($channel->id);

        $response = $this->actingAs($user)->post(route('videos.analyze', $video));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $video->refresh();
        $this->assertSame('completed', $video->analysis_status);
        $this->assertSame(self::ANALYSIS['title'], $video->ai_title);
        $this->assertSame(self::ANALYSIS['hook'], $video->ai_hook);
        $this->assertSame(self::ANALYSIS['hashtags'], $video->ai_hashtags);
        $this->assertSame(92, $video->ai_virality_score);
        $this->assertSame('gemini-1.5-flash', $video->model_used);
        $this->assertNotNull($video->analyzed_at);
        // Upload metadata updated so the dashboard shows what went live.
        $this->assertSame(self::ANALYSIS['title'], $video->title);
        $this->assertSame(self::ANALYSIS['description'], $video->description);
        $this->assertSame(self::ANALYSIS['hook'], $video->ai_data['hook']);
    }

    public function test_manual_analyze_repairs_fenced_json(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini("Here you go:\n```json\n".json_encode(self::ANALYSIS)."\n```");
        $video = $this->createVideoWithFile($channel->id);

        $this->actingAs($user)->post(route('videos.analyze', $video));

        $this->assertSame('completed', $video->fresh()->analysis_status);
        $this->assertSame(self::ANALYSIS['title'], $video->fresh()->ai_title);
    }

    public function test_manual_analyze_failure_marks_failed_and_keeps_metadata(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini('{}', 500);
        $video = $this->createVideoWithFile($channel->id, 'Original Title');

        $response = $this->actingAs($user)->post(route('videos.analyze', $video));

        $response->assertSessionHasErrors('gemini');
        $video->refresh();
        $this->assertSame('failed', $video->analysis_status);
        $this->assertSame('Original Title', $video->title);
        $this->assertNull($video->ai_title);
    }

    public function test_manual_analyze_rejects_invalid_json(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini('{"hook":"only-this"}');
        $video = $this->createVideoWithFile($channel->id);

        $this->actingAs($user)->post(route('videos.analyze', $video));

        $this->assertSame('failed', $video->fresh()->analysis_status);
    }

    public function test_manual_analyze_requires_enabled_setting(): void
    {
        [$user, $channel] = $this->createUserWithChannelAndYoutube();
        Http::fake();
        $video = $this->createVideoWithFile($channel->id);

        $response = $this->actingAs($user)->post(route('videos.analyze', $video));

        $response->assertSessionHasErrors('gemini');
        Http::assertNothingSent();
        $this->assertSame('none', $video->fresh()->analysis_status);
    }

    public function test_cron_uses_ai_metadata_when_enabled(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini();
        $video = $this->createVideoWithFile($channel->id, 'Original Title');
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertExitCode(0);

        $pub = Publication::first();
        $this->assertSame('published', $pub->status);
        $this->assertSame(self::ANALYSIS['title'], $pub->custom_title);
        $this->assertStringContainsString(self::ANALYSIS['description'], $pub->custom_caption);
        $this->assertStringContainsString(implode(' ', self::ANALYSIS['hashtags']), $pub->custom_caption);
        $this->assertSame('completed', $video->fresh()->analysis_status);
    }

    public function test_cron_falls_back_to_existing_metadata_on_gemini_failure(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini('{}', 500);
        $video = $this->createVideoWithFile($channel->id, 'Original Title');
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertExitCode(0);

        // Upload still happened, with the existing metadata.
        $pub = Publication::first();
        $this->assertSame('published', $pub->status);
        $this->assertSame('Original Title', $pub->custom_title);
        $this->assertSame('failed', $video->fresh()->analysis_status);
        $this->assertSame('Original Title', $video->fresh()->title);
    }

    public function test_cron_reuses_completed_analysis_without_second_gemini_call(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini();
        $video = $this->createVideoWithFile($channel->id, 'Original Title');
        $video->update([
            'analysis_status' => 'completed',
            'ai_title' => 'Stored AI Title',
            'ai_hashtags' => ['shorts', 'viral'],
            'ai_description' => 'Stored description',
        ]);
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertExitCode(0);

        $pub = Publication::first();
        $this->assertSame('published', $pub->status);
        $this->assertSame('Stored AI Title', $pub->custom_title);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), ':generateContent'));
    }

    public function test_cron_skips_gemini_when_disabled(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        Http::fake();
        $video = $this->createVideoWithFile($channel->id, 'Original Title');
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due')->assertExitCode(0);

        Http::assertNothingSent();
        $pub = Publication::first();
        $this->assertSame('published', $pub->status);
        $this->assertSame('Original Title', $pub->custom_title);
        $this->assertSame('none', $video->fresh()->analysis_status);
    }

    public function test_cron_analysis_uses_video_timestamp(): void
    {
        [$user, $channel, $account] = $this->createUserWithChannelAndYoutube();
        $this->enableGemini();
        $this->fakeGemini();
        $video = $this->createVideoWithFile($channel->id);
        $this->scheduleDuePublication($channel->id, $account->id, $video->id);

        $this->artisan('publications:process-due');

        $this->assertNotNull($video->fresh()->analyzed_at);
    }
}
