<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Models\AiVideoJob;
use App\Models\Channel;
use App\Models\User;
use App\Models\Video;
use App\Services\Ai\VideoRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeImageProvider;
use Tests\Fakes\FakeTextProvider;
use Tests\Fakes\FakeVideoRenderer;
use Tests\Fakes\FakeVoiceProvider;
use Tests\TestCase;

class AiVideoControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->channel = Channel::create(['user_id' => $this->user->id, 'name' => 'Test Channel']);

        config([
            'ai.providers.groq.class' => FakeTextProvider::class,
            'ai.providers.huggingface.class' => FakeImageProvider::class,
            'ai.providers.edge_tts.class' => FakeVoiceProvider::class,
        ]);
        $this->app->instance(FakeTextProvider::class, new FakeTextProvider);
        $this->app->instance(FakeImageProvider::class, new FakeImageProvider);
        $this->app->instance(FakeVoiceProvider::class, new FakeVoiceProvider);
        $this->app->instance(VideoRenderer::class, new FakeVideoRenderer);

        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
    }

    private function configureContentType(): void
    {
        foreach ([['groq', 'text'], ['huggingface', 'image'], ['edge_tts', 'voice']] as [$provider, $type]) {
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
    }

    private function createPagePayload(array $overrides = []): array
    {
        return array_merge([
            'video_file' => UploadedFile::fake()->create('bg.mp4', 100, 'video/mp4'),
            'topic' => 'The strange intelligence of octopuses',
            'content_type' => 'video',
            'scenes_count' => '3',
            'language' => 'en',
            'tone' => 'engaging',
            'rights_confirmed' => '1',
            'audio_mode' => 'mute',
        ], $overrides);
    }

    public function test_create_page_requires_configuration_pointer(): void
    {
        // No connections configured — the page still renders (with a hint).
        $this->actingAs($this->user)->get(route('ai.videos.create'))
            ->assertOk()
            ->assertSee('AI Providers');
    }

    public function test_store_rejects_without_rights_confirmation(): void
    {
        $this->configureContentType();

        $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload(['rights_confirmed' => null]))
            ->assertSessionHasErrors('rights_confirmed');

        $this->assertDatabaseCount('ai_video_jobs', 0);
    }

    public function test_store_rejects_when_content_type_has_no_ai_config(): void
    {
        // 'shorts' has no connections assigned even though video does.
        $this->configureContentType();

        $response = $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload(['content_type' => 'shorts']));

        $response->assertSessionHasErrors('ai_config');
        $this->assertDatabaseCount('ai_video_jobs', 0);
    }

    public function test_store_runs_full_pipeline_and_completes(): void
    {
        $this->configureContentType();

        $response = $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload());

        $response->assertRedirect();
        $job = AiVideoJob::first();
        $this->assertNotNull($job);
        $this->assertSame(AiVideoJob::STATUS_COMPLETED, $job->fresh()->status, 'Sync queue must run the pipeline inline');
        $this->assertStringStartsWith('ai_backgrounds/', $job->background_path);
        Storage::disk('public')->assertExists($job->background_path);
    }

    public function test_progress_endpoint_reports_live_state(): void
    {
        $this->configureContentType();
        $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload());
        $job = AiVideoJob::first();

        $this->actingAs($this->user)->getJson(route('ai.videos.progress', $job))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('final_url', '/storage/videos/ai-'.$job->id.'.mp4')
            ->assertJsonCount(3, 'scenes');
    }

    public function test_progress_endpoint_denies_other_users(): void
    {
        $this->configureContentType();
        $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload());
        $job = AiVideoJob::first();
        $other = User::factory()->create();
        Channel::create(['user_id' => $other->id, 'name' => 'Other Channel']);

        $this->actingAs($other)->getJson(route('ai.videos.progress', $job))->assertForbidden();
        $this->actingAs($other)->get(route('ai.videos.show', $job))->assertForbidden();
    }

    public function test_approve_creates_library_video_and_redirects_to_scheduling(): void
    {
        $this->configureContentType();
        $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload());
        $job = AiVideoJob::first();

        $this->actingAs($this->user)->post(route('ai.videos.approve', $job))->assertRedirect();

        $video = Video::first();
        $this->assertNotNull($video);
        $this->assertSame('ready', $video->status);
        $this->assertSame($job->final_path, $video->file_path);
        $this->assertSame($video->id, $job->fresh()->video_id);
        $this->assertSame('Test Video Title', $video->title);

        // The video is playable from the library.
        $this->actingAs($this->user)->get(route('videos.show', $video))
            ->assertOk()
            ->assertSee('<video', false);
    }

    public function test_approve_rejected_when_job_not_completed(): void
    {
        $this->configureContentType();
        $job = AiVideoJob::create([
            'user_id' => $this->user->id,
            'channel_id' => $this->channel->id,
            'content_type' => 'video',
            'topic' => 'x',
            'background_path' => 'ai_backgrounds/bg.mp4',
            'status' => AiVideoJob::STATUS_QUEUED,
            'stage' => 'analyzing',
        ]);

        $this->actingAs($this->user)->post(route('ai.videos.approve', $job))
            ->assertSessionHasErrors('approve');

        $this->assertDatabaseCount('videos', 0);
    }

    public function test_scene_image_endpoint_serves_generated_image(): void
    {
        $this->configureContentType();
        $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload());
        $job = AiVideoJob::first();

        $this->actingAs($this->user)->get(route('ai.videos.image', [$job, 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_edit_page_and_save_regenerates_from_images_stage(): void
    {
        $this->configureContentType();
        $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload());
        $job = AiVideoJob::first();

        $this->actingAs($this->user)->get(route('ai.videos.edit', $job))
            ->assertOk()
            ->assertSee('Narration');

        $scenes = $job->scenes;
        $scenes[0]['narration'] = 'Completely rewritten first scene narration.';
        $scenes[0]['image_prompt'] = 'A brand new visual concept for scene one.';

        // Stop the sync-queue inline kick so we can inspect the requeued state.
        config(['queue.default' => 'database']);

        $this->actingAs($this->user)->put(route('ai.videos.update', $job), [
            'title' => 'New Title',
            'description' => 'New description',
            'narration' => 'Completely rewritten first scene narration.',
            'scenes' => array_map(fn ($s) => [
                'scene_number' => $s['scene_number'],
                'narration' => $s['narration'],
                'image_prompt' => $s['image_prompt'],
            ], $scenes),
            'audio_mode' => 'keep',
        ])->assertRedirect();

        $job->refresh();
        $this->assertSame('New Title', $job->title);
        $this->assertSame('images', $job->stage);
        $this->assertSame('pending', $job->scenes[0]['image_status'], 'Changed prompt must regenerate the image');
        $this->assertSame('done', $job->scenes[1]['image_status'], 'Unchanged scene keeps its image');
        $this->assertSame('keep', $job->progress['audio_mode']);
    }

    public function test_retry_stage_endpoint_requeues_from_stage(): void
    {
        $this->configureContentType();
        $this->actingAs($this->user)->post(route('ai.videos.store'), $this->createPagePayload());
        $job = AiVideoJob::first();

        // Stop the sync-queue inline kick so we can inspect the requeued state.
        config(['queue.default' => 'database']);

        $this->actingAs($this->user)->post(route('ai.videos.retry', [$job, 'voice']))
            ->assertRedirect();

        $job->refresh();
        $this->assertSame('voice', $job->stage);
        $this->assertNull($job->voice);
        $this->assertSame(AiVideoJob::STATUS_QUEUED, $job->status);

        // Unknown stage → 404.
        $this->actingAs($this->user)->post(route('ai.videos.retry', [$job, 'bogus']))->assertNotFound();
    }
}
