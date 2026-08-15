<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiConnectionTesterTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        return $user;
    }

    public function test_text_connection_returns_ok_when_provider_responds(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response('{"choices":[{"message":{"content":"{\"result\":\"OK\"}"}}]}', 200),
        ]);

        $user = $this->createUser();

        $response = $this->actingAs($user)->postJson(route('settings.ai.connections.test'), [
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'gsk_abc123',
            'model' => 'llama-3.3-70b-versatile',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $this->assertStringContainsString('text API responded', $response->json('message'));

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer gsk_abc123');
        });
    }

    public function test_bad_key_reports_failure_without_leaking_the_key(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response('{"error":{"message":"Invalid API key"}}', 401),
        ]);

        $user = $this->createUser();

        $response = $this->actingAs($user)->postJson(route('settings.ai.connections.test'), [
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'gsk_wrong_secret_value',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => false]);
        $response->assertDontSee('gsk_wrong_secret_value');
        $this->assertStringContainsString('rejected the API key', $response->json('message'));
    }

    public function test_blank_key_uses_the_saved_key(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response('{"choices":[{"message":{"content":"{\"result\":\"OK\"}"}}]}', 200),
        ]);

        $user = $this->createUser();
        $connection = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'saved-secret-key',
            'is_active' => true,
        ]);

        $this->actingAs($user)->postJson(route('settings.ai.connections.test'), [
            'id' => $connection->id,
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => '',
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer saved-secret-key');
        });
    }

    public function test_voice_connection_returns_ok(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response('FAKE-MP3-BYTES', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $user = $this->createUser();

        $this->actingAs($user)->postJson(route('settings.ai.connections.test'), [
            'type' => 'voice',
            'provider' => 'pollinations_voice',
            'api_key' => null,
        ])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_image_connection_reports_provider_error(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response(
                '{"success":false,"error":{"message":"Authentication required.","code":"UNAUTHORIZED"},"status":401}',
                401
            ),
        ]);

        $user = $this->createUser();

        $this->actingAs($user)->postJson(route('settings.ai.connections.test'), [
            'type' => 'image',
            'provider' => 'pollinations_image',
            'api_key' => 'sk_bad',
        ])->assertOk()->assertJson(['ok' => false]);
    }

    public function test_provider_type_mismatch_is_rejected(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson(route('settings.ai.connections.test'), [
            'type' => 'image',
            'provider' => 'groq',
            'api_key' => 'x',
        ])->assertStatus(422);
    }

    public function test_cannot_test_another_users_connection(): void
    {
        $owner = $this->createUser();
        $intruder = $this->createUser();
        $connection = AiConnection::create([
            'user_id' => $owner->id,
            'name' => 'Owner',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'owner-key',
            'is_active' => true,
        ]);

        $this->actingAs($intruder)->postJson(route('settings.ai.connections.test'), [
            'id' => $connection->id,
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => '',
        ])->assertStatus(404);
    }
}
