<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\AiContentTypeConfig;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetupAiDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithStack(): User
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        // The real-world state before setup: Groq text with a key, and a
        // Pollinations TEXT connection (the user meant it for images).
        AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Transcript',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'gsk_real_key',
            'is_active' => true,
        ]);
        AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Images',
            'type' => 'text',
            'provider' => 'pollinations',
            'api_key' => 'sk_real_key',
            'is_active' => true,
        ]);

        return $user;
    }

    public function test_wires_groq_pollinations_edge_tts_into_every_content_type(): void
    {
        $user = $this->userWithStack();

        $exit = Artisan::call('ai:setup-defaults', ['--user' => (string) $user->id]);

        $this->assertSame(0, $exit);

        // Pollinations text was converted to image — same key, no duplicate.
        $image = AiConnection::where('user_id', $user->id)->where('provider', 'pollinations_image')->first();
        $this->assertNotNull($image);
        $this->assertSame('image', $image->type);
        $this->assertSame('sk_real_key', $image->api_key, 'The user key must survive the conversion.');
        $this->assertDatabaseMissing('ai_connections', ['user_id' => $user->id, 'provider' => 'pollinations', 'type' => 'text']);

        $groq = AiConnection::where('user_id', $user->id)->where('provider', 'groq')->first();
        $voice = AiConnection::where('user_id', $user->id)->where('provider', 'edge_tts')->first();
        $this->assertNotNull($groq);
        $this->assertNotNull($voice);

        $contentTypes = config('ai.content_types');
        foreach ([$groq, $image, $voice] as $connection) {
            $this->assertSame($contentTypes, $connection->assignedContentTypes());
        }

        $this->assertDatabaseCount('ai_content_type_configs', count($contentTypes) * 3);

        // The pipeline now resolves the stack for every content type.
        $config = app(PipelineConfig::class);
        foreach ($contentTypes as $type) {
            $this->assertSame($groq->id, $config->connectionsFor($user->id, $type, 'text')[0]->id, "text for {$type}");
            $this->assertSame($image->id, $config->connectionsFor($user->id, $type, 'image')[0]->id, "image for {$type}");
            $this->assertSame($voice->id, $config->connectionsFor($user->id, $type, 'voice')[0]->id, "voice for {$type}");
        }
    }

    public function test_is_idempotent_and_does_not_touch_fallbacks(): void
    {
        $user = $this->userWithStack();

        Artisan::call('ai:setup-defaults', ['--user' => (string) $user->id]);
        Artisan::call('ai:setup-defaults', ['--user' => (string) $user->id]);

        $this->assertSame(1, AiConnection::where('user_id', $user->id)->where('provider', 'pollinations_image')->count());
        $this->assertSame(1, AiConnection::where('user_id', $user->id)->where('provider', 'edge_tts')->count());

        // A pre-existing fallback must survive the re-run.
        $type = config('ai.content_types')[0];
        $fallback = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Backup Voice',
            'type' => 'voice',
            'provider' => 'elevenlabs',
            'api_key' => null,
            'is_active' => true,
        ]);
        AiContentTypeConfig::updateOrCreate(
            ['user_id' => $user->id, 'content_type' => $type, 'role' => 'voice_fallback'],
            ['ai_connection_id' => $fallback->id]
        );

        Artisan::call('ai:setup-defaults', ['--user' => (string) $user->id]);

        $this->assertSame(
            $fallback->id,
            AiContentTypeConfig::where('user_id', $user->id)
                ->where('content_type', $type)
                ->where('role', 'voice_fallback')
                ->value('ai_connection_id')
        );
    }
}
