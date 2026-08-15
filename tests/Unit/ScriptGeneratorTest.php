<?php

namespace Tests\Unit;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Models\AiVideoJob;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\ScriptGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeTextProvider;
use Tests\TestCase;

class ScriptGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Channel::create(['user_id' => $this->user->id, 'name' => 'Test Channel']);

        config(['ai.providers.groq.class' => FakeTextProvider::class]);

        $connection = AiConnection::create([
            'user_id' => $this->user->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'is_active' => true,
        ]);
        $connection->syncContentTypes(['video']);
        AiContentTypeConfig::create([
            'user_id' => $this->user->id,
            'content_type' => 'video',
            'role' => 'text_primary',
            'ai_connection_id' => $connection->id,
        ]);
    }

    private function job(int $scenes = 5): AiVideoJob
    {
        return AiVideoJob::create([
            'user_id' => $this->user->id,
            'channel_id' => 1,
            'content_type' => 'video',
            'topic' => 'The strange intelligence of octopuses',
            'language' => 'en',
            'tone' => 'engaging',
            'scenes_count' => $scenes,
            'background_path' => 'bg.mp4',
            'status' => 'queued',
            'stage' => 'script',
        ]);
    }

    public function test_generates_and_validates_a_script(): void
    {
        $script = app(ScriptGenerator::class)->generate($this->job(5));

        $this->assertSame('Test Video Title', $script['title']);
        $this->assertCount(5, $script['scenes']);
        $this->assertArrayHasKey('image_prompt', $script['scenes'][0]);
        $this->assertNotSame('', trim($script['scenes'][0]['narration']));
    }

    public function test_retries_when_first_response_is_invalid_json(): void
    {
        $provider = new FakeTextProvider;
        $provider->responses = ['this is not json at all'];
        $this->app->instance(FakeTextProvider::class, $provider);

        $script = app(ScriptGenerator::class)->generate($this->job(3));

        $this->assertCount(3, $script['scenes']);
        $this->assertSame(2, $provider->calls, 'Must retry the invalid response');
    }

    public function test_rejects_wrong_scene_count_then_retries(): void
    {
        $provider = new FakeTextProvider;
        $provider->responses = [json_encode([
            'title' => 'X',
            'description' => 'Y',
            'narration' => 'Only one scene here.',
            'scenes' => [
                ['scene_number' => 1, 'narration' => 'One scene.', 'image_prompt' => 'Only one scene image.'],
            ],
        ])];
        $this->app->instance(FakeTextProvider::class, $provider);

        $script = app(ScriptGenerator::class)->generate($this->job(5));

        $this->assertCount(5, $script['scenes'], 'Wrong scene count must be rejected and re-requested');
        $this->assertSame(2, $provider->calls);
    }

    public function test_fails_after_three_invalid_responses(): void
    {
        $provider = new FakeTextProvider;
        $provider->responses = ['bad', 'worse', 'nope'];
        $this->app->instance(FakeTextProvider::class, $provider);

        try {
            app(ScriptGenerator::class)->generate($this->job(5));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('invalid script', $e->getMessage());
        }

        $this->assertSame(3, $provider->calls);
    }

    public function test_records_provider_used_on_the_job(): void
    {
        $job = $this->job(3);
        app(ScriptGenerator::class)->generate($job);

        $this->assertSame('groq', $job->fresh()->providers_used['text']['provider']);
    }
}
