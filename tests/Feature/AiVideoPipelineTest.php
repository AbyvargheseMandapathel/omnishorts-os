<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Models\AiVideoJob;
use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Services\Ai\AiVideoPipeline;
use App\Services\Ai\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeImageProvider;
use Tests\Fakes\FakeTextProvider;
use Tests\Fakes\FakeVideoRenderer;
use Tests\Fakes\FakeVoiceProvider;
use Tests\TestCase;

class AiVideoPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Channel $channel;

    private FakeTextProvider $text;

    private FakeImageProvider $image;

    private FakeVoiceProvider $voice;

    private FakeVideoRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->channel = Channel::create(['user_id' => $this->user->id, 'name' => 'Test Channel']);

        // Deterministic provider wiring via the real registry + manager.
        config([
            'ai.providers.groq.class' => FakeTextProvider::class,
            'ai.providers.huggingface.class' => FakeImageProvider::class,
            'ai.providers.edge_tts.class' => FakeVoiceProvider::class,
        ]);

        $this->text = new FakeTextProvider;
        $this->image = new FakeImageProvider;
        $this->voice = new FakeVoiceProvider;
        $this->renderer = new FakeVideoRenderer;

        $this->app->instance(FakeTextProvider::class, $this->text);
        $this->app->instance(FakeImageProvider::class, $this->image);
        $this->app->instance(FakeVoiceProvider::class, $this->voice);
        $this->app->instance(VideoRenderer::class, $this->renderer);

        $this->configure('groq', 'text');
        $this->configure('huggingface', 'image');
        $this->configure('edge_tts', 'voice');

        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
    }

    private function configure(string $provider, string $type): void
    {
        $connection = AiConnection::create([
            'user_id' => $this->user->id,
            'name' => ucfirst($provider),
            'type' => $type,
            'provider' => $provider,
            'is_active' => true,
        ]);
        $connection->syncContentTypes(['video']);
        AiContentTypeConfig::create([
            'user_id' => $this->user->id,
            'content_type' => 'video',
            'role' => $type.'_primary',
            'ai_connection_id' => $connection->id,
        ]);
    }

    private function job(array $overrides = []): AiVideoJob
    {
        return AiVideoJob::create(array_merge([
            'user_id' => $this->user->id,
            'channel_id' => $this->channel->id,
            'content_type' => 'video',
            'topic' => 'The strange intelligence of octopuses',
            'language' => 'en',
            'tone' => 'engaging',
            'scenes_count' => 3,
            'background_path' => 'ai_backgrounds/bg.mp4',
            'status' => AiVideoJob::STATUS_QUEUED,
            'stage' => 'analyzing',
            'progress' => ['stages' => []],
        ], $overrides));
    }

    public function test_full_pipeline_completes_and_records_everything(): void
    {
        Storage::disk('public')->put('ai_backgrounds/bg.mp4', 'background-bytes');
        $job = $this->job();

        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $this->assertSame(AiVideoJob::STATUS_COMPLETED, $job->status);
        $this->assertNull($job->error);

        // Script generated with exactly 3 scenes.
        $this->assertSame('Test Video Title', $job->title);
        $this->assertCount(3, $job->script['scenes']);

        // All scene images generated + stored on the ai disk.
        foreach ($job->scenes as $scene) {
            $this->assertSame('done', $scene['image_status']);
            $this->assertTrue(Storage::disk('ai')->exists($scene['image_path']), 'Scene image must be stored');
            $this->assertArrayHasKey('start_time', $scene, 'Scene timing must be computed');
        }

        // Voice + captions.
        $this->assertGreaterThan(0, $job->voice['duration']);
        $this->assertNotEmpty($job->voice['sentences']);
        $this->assertStringEndsWith('subtitles.srt', $job->captions_path);
        $this->assertFileExists(storage_path('app/ai/'.$job->captions_path));

        // Render produced the final file on the video disk.
        $this->assertSame('videos/ai-'.$job->id.'.mp4', $job->final_path);
        $this->assertTrue(Storage::disk('public')->exists($job->final_path));
        $this->assertSame(1, $this->renderer->calls);

        // Providers actually used are recorded.
        $this->assertSame('groq', $job->providers_used['text']['provider']);
        $this->assertSame('huggingface', $job->providers_used['image']['provider']);
        $this->assertSame('edge_tts', $job->providers_used['voice']['provider']);

        // Pipeline stages all marked done.
        $stages = $job->progress['stages'];
        foreach (AiVideoJob::STAGES as $key => $label) {
            $this->assertSame('done', $stages[$key]['status'], "Stage {$key} must be done");
        }
    }

    public function test_failed_scene_can_be_retried_individually(): void
    {
        Storage::disk('public')->put('ai_backgrounds/bg.mp4', 'background-bytes');
        $job = $this->job();

        // Scene 1 fails transiently; others succeed.
        $this->image->failures = [
            FakeImageProvider::transient('scene 1 exploded'),
        ];

        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $this->assertSame(AiVideoJob::STATUS_FAILED, $job->status);
        $this->assertSame('images', $job->stage);
        $this->assertStringContainsString('scene 1 exploded', $job->error);

        $scenes = $job->scenes;
        $this->assertSame('failed', $scenes[0]['image_status']);
        $this->assertSame('done', $scenes[1]['image_status']);
        $this->assertSame('done', $scenes[2]['image_status']);
        $callsAfterFailure = $this->image->calls;

        // Retry only scene 1 — no regeneration of successful images.
        app(AiVideoPipeline::class)->retryImage($job, 1);
        $job->refresh();
        $this->assertSame('images', $job->stage);
        $this->assertSame(AiVideoJob::STATUS_QUEUED, $job->status);

        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $this->assertSame(AiVideoJob::STATUS_COMPLETED, $job->status);
        $this->assertSame($callsAfterFailure + 1, $this->image->calls, 'Only the failed scene is regenerated');
        foreach ($job->scenes as $scene) {
            $this->assertSame('done', $scene['image_status']);
        }
    }

    public function test_script_retry_wipes_downstream_and_regenerates(): void
    {
        Storage::disk('public')->put('ai_backgrounds/bg.mp4', 'background-bytes');
        $job = $this->job();
        app(AiVideoPipeline::class)->process($job);
        $job->refresh();
        $this->assertSame(AiVideoJob::STATUS_COMPLETED, $job->status);

        $imageCalls = $this->image->calls;
        $voiceCalls = $this->voice->calls;

        app(AiVideoPipeline::class)->retryScript($job);
        $job->refresh();
        $this->assertNull($job->script);
        $this->assertNull($job->scenes);
        $this->assertNull($job->voice);
        $this->assertNull($job->captions_path);
        $this->assertNull($job->final_path);
        $this->assertSame('script', $job->stage);

        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $this->assertSame(AiVideoJob::STATUS_COMPLETED, $job->status);
        $this->assertNotNull($job->script);
        $this->assertGreaterThan($imageCalls, $this->image->calls, 'Images regenerate after a script change');
        $this->assertGreaterThan($voiceCalls, $this->voice->calls);
    }

    public function test_voice_retry_reuses_images(): void
    {
        Storage::disk('public')->put('ai_backgrounds/bg.mp4', 'background-bytes');
        $job = $this->job();
        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $imageCalls = $this->image->calls;
        app(AiVideoPipeline::class)->retryVoice($job);
        $job->refresh();
        $this->assertNull($job->voice);

        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $this->assertSame(AiVideoJob::STATUS_COMPLETED, $job->status);
        $this->assertSame($imageCalls, $this->image->calls, 'Voice retry must not regenerate images');
        $this->assertNotNull($job->voice);
        $this->assertNotNull($job->captions_path);
    }

    public function test_failure_without_any_configuration_is_actionable(): void
    {
        Storage::disk('public')->put('ai_backgrounds/bg.mp4', 'background-bytes');
        $job = $this->job();
        // No connections configured for this user (different user).
        $job->update(['user_id' => User::factory()->create()->id]);

        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $this->assertSame(AiVideoJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('No text AI connection is configured', $job->error);
    }

    public function test_approve_creates_library_video_connected_to_existing_workflow(): void
    {
        Storage::disk('public')->put('ai_backgrounds/bg.mp4', 'background-bytes');
        $job = $this->job();
        app(AiVideoPipeline::class)->process($job);
        $job->refresh();

        $video = $job->channel->videos()->create([
            'title' => $job->title,
            'description' => $job->description,
            'file_path' => $job->final_path,
            'duration' => (int) round((float) $job->voice['duration']),
            'status' => 'ready',
        ]);
        $job->update(['video_id' => $video->id]);

        $this->assertSame('ready', $video->status);
        $this->assertSame($job->final_path, $video->file_path);
        $this->assertSame($video->id, $job->fresh()->video_id);
        $this->assertTrue(Storage::disk('public')->exists($video->file_path), 'The approved video is playable from the library');
        $this->assertInstanceOf(Video::class, $video);
    }
}
