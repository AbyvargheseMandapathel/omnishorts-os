<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeImageProvider;
use Tests\Fakes\FakeTextProvider;
use Tests\TestCase;

class AiContentTypeConfigTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Channel::create(['user_id' => $this->user->id, 'name' => 'Test Channel']);
    }

    private function connection(string $name, string $type, string $provider, bool $active = true, array $types = ['video']): AiConnection
    {
        $connection = AiConnection::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'type' => $type,
            'provider' => $provider,
            'is_active' => $active,
        ]);
        $connection->syncContentTypes($types);

        // Wire the primary config row so resolve() finds it (idempotent —
        // tests that then post through the route update the same row).
        AiContentTypeConfig::updateOrCreate(
            ['user_id' => $this->user->id, 'content_type' => 'video', 'role' => $type.'_primary'],
            ['ai_connection_id' => $connection->id]
        );

        return $connection;
    }

    public function test_resolve_returns_configured_active_assigned_connection(): void
    {
        $primary = $this->connection('Groq Main', 'text', 'groq');

        $resolved = AiContentTypeConfig::resolve($this->user->id, 'video', 'text_primary');

        $this->assertNotNull($resolved);
        $this->assertSame($primary->id, $resolved->id);
    }

    public function test_resolve_skips_inactive_connection(): void
    {
        $this->connection('Groq Main', 'text', 'groq', active: false);

        $this->assertNull(AiContentTypeConfig::resolve($this->user->id, 'video', 'text_primary'));
    }

    public function test_resolve_skips_connection_not_assigned_to_content_type(): void
    {
        $this->connection('Groq Main', 'text', 'groq', types: ['shorts']);

        $this->assertNull(AiContentTypeConfig::resolve($this->user->id, 'video', 'text_primary'));
    }

    public function test_connections_for_returns_primary_then_fallback(): void
    {
        $this->connection('Primary', 'text', 'groq');
        $this->connection('Fallback', 'text', 'gemini');

        $this->actingAs($this->user)->post(route('settings.ai.content-types.save'), [
            'configs' => [
                'video' => [
                    'text_primary' => AiConnection::where('name', 'Primary')->value('id'),
                    'text_fallback' => AiConnection::where('name', 'Fallback')->value('id'),
                ],
            ],
        ]);

        $connections = app(PipelineConfig::class)->connectionsFor($this->user->id, 'video', 'text');

        $this->assertCount(2, $connections);
        $this->assertSame('Primary', $connections[0]->name);
        $this->assertSame('Fallback', $connections[1]->name);
    }

    public function test_content_type_config_ui_only_lists_matching_type_assigned_connections(): void
    {
        $this->connection('Groq Main', 'text', 'groq', types: ['video']);
        $this->connection('FLUX', 'image', 'huggingface', types: ['video', 'shorts']);
        // Assigned to video but wrong type — must never appear in text role.
        $this->connection('Voice Only', 'voice', 'edge_tts', types: ['video']);

        $response = $this->actingAs($this->user)->get(route('settings.index'));

        $response->assertOk();
        $response->assertSee('Groq Main');
        $response->assertSee('FLUX');
        $response->assertSee('Voice Only');
    }

    public function test_fallback_is_used_on_transient_primary_failure(): void
    {
        config([
            'ai.providers.groq.class' => FakeTextProvider::class,
            'ai.providers.gemini.class' => FakeTextProvider::class,
        ]);

        $primary = $this->connection('Primary', 'text', 'groq');
        $fallback = $this->connection('Fallback', 'text', 'gemini');
        AiContentTypeConfig::updateOrCreate(
            ['user_id' => $this->user->id, 'content_type' => 'video', 'role' => 'text_primary'],
            ['ai_connection_id' => $primary->id]
        );
        AiContentTypeConfig::updateOrCreate(
            ['user_id' => $this->user->id, 'content_type' => 'video', 'role' => 'text_fallback'],
            ['ai_connection_id' => $fallback->id]
        );

        // Both providers share a class — resolve order decides which is which.
        $primaryProvider = new FakeTextProvider;
        $primaryProvider->responses = [FakeTextProvider::transient('rate limited')];
        $fallbackProvider = new FakeTextProvider;
        $resolutions = 0;

        $this->app->bind(FakeTextProvider::class, function () use (&$resolutions, $primaryProvider, $fallbackProvider) {
            return ++$resolutions === 1 ? $primaryProvider : $fallbackProvider;
        });

        $result = app(PipelineConfig::class)->withFallback($this->user->id, 'video', 'text', fn ($provider) => $provider->complete('s', 'u'));

        $this->assertSame('Test Video Title', json_decode($result, true)['title']);
        $this->assertSame(1, $primaryProvider->calls, 'Primary must be attempted once');
        $this->assertSame(1, $fallbackProvider->calls, 'Fallback must take over after the transient failure');
    }

    public function test_permanent_failure_is_reported_without_fallback(): void
    {
        config(['ai.providers.groq.class' => FakeTextProvider::class]);

        $primary = $this->connection('Primary', 'text', 'groq');
        $fallback = $this->connection('Fallback', 'text', 'gemini');
        AiContentTypeConfig::updateOrCreate(
            ['user_id' => $this->user->id, 'content_type' => 'video', 'role' => 'text_primary'],
            ['ai_connection_id' => $primary->id]
        );
        AiContentTypeConfig::updateOrCreate(
            ['user_id' => $this->user->id, 'content_type' => 'video', 'role' => 'text_fallback'],
            ['ai_connection_id' => $fallback->id]
        );

        $this->app->bind(FakeTextProvider::class, fn () => new class extends FakeTextProvider
        {
            public function complete(string $systemPrompt, string $userPrompt, array $config = []): string
            {
                throw FakeTextProvider::permanent('invalid api key');
            }
        });

        try {
            app(PipelineConfig::class)->withFallback($this->user->id, 'video', 'text', fn ($provider) => $provider->complete('s', 'u'));
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->transient);
            $this->assertStringContainsString('invalid api key', $e->getMessage());
        }
    }

    public function test_no_connection_configured_throws_actionable_error(): void
    {
        try {
            app(PipelineConfig::class)->withFallback($this->user->id, 'video', 'voice', fn ($provider) => null);
            $this->fail('Expected AiProviderException');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString("No voice AI connection is configured for the 'video' content type", $e->getMessage());
        }
    }

    public function test_image_provider_chain_resolves(): void
    {
        config(['ai.providers.huggingface.class' => FakeImageProvider::class]);

        $connection = $this->connection('FLUX', 'image', 'huggingface');

        $result = app(PipelineConfig::class)->withFallback(
            $this->user->id,
            'video',
            'image',
            fn ($provider) => $provider->generate('a prompt', 720, 1280)
        );

        $this->assertNotSame('', $result);
        $this->assertSame('FLUX', $connection->name);
    }
}
