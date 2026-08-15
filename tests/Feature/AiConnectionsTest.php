<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\ProviderManager;
use App\Services\Ai\Providers\OpenAITextProvider;
use App\Services\Ai\Providers\PollinationsImageProvider;
use App\Services\Ai\Providers\PollinationsVoiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        return $user;
    }

    public function test_api_key_is_encrypted_at_rest_and_decrypts_back(): void
    {
        $user = $this->createUser();

        $connection = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'gsk_super_secret_key_value',
            'model' => 'llama-3.3-70b-versatile',
            'is_active' => true,
        ]);

        $raw = \DB::table('ai_connections')->where('id', $connection->id)->value('api_key');
        $this->assertNotSame('gsk_super_secret_key_value', $raw);
        $this->assertStringStartsWith('eyJ', $raw, 'Key must be stored as Laravel ciphertext (base64 JSON payload)');

        $this->assertSame('gsk_super_secret_key_value', $connection->fresh()->api_key);
    }

    public function test_connection_never_leaks_key_in_http_response(): void
    {
        $user = $this->createUser();
        $connection = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'super-secret-gsk-key',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('settings.index'));

        $response->assertOk();
        // The secret itself must never appear (placeholders like 'gsk_…' are fine).
        $response->assertDontSee('super-secret-gsk-key');
        $response->assertDontSee('secret-gsk-key');
        $response->assertSee('Groq Main');
    }

    public function test_create_connection_with_content_type_assignment(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->post(route('settings.ai.connections.save'), [
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'gsk_abc123',
            'model' => 'llama-3.3-70b-versatile',
            'content_types' => ['video', 'shorts'],
            'is_active' => '1',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $connection = AiConnection::where('name', 'Groq Main')->first();
        $this->assertNotNull($connection);
        $this->assertSame(['video', 'shorts'], $connection->assignedContentTypes());
        $this->assertSame('gsk_abc123', $connection->api_key);
        $this->assertDatabaseCount('ai_connection_content_types', 2);
    }

    public function test_provider_type_mismatch_is_rejected(): void
    {
        $user = $this->createUser();

        // groq is a text provider — declaring it as image must fail validation.
        $response = $this->actingAs($user)->post(route('settings.ai.connections.save'), [
            'name' => 'Bad Connection',
            'type' => 'image',
            'provider' => 'groq',
            'api_key' => 'x',
        ]);

        $response->assertSessionHasErrors('provider');
        $this->assertDatabaseCount('ai_connections', 0);
    }

    public function test_update_with_blank_key_keeps_saved_key(): void
    {
        $user = $this->createUser();
        $connection = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'keep-me',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('settings.ai.connections.save'), [
            'id' => $connection->id,
            'name' => 'Groq Renamed',
            'type' => 'text',
            'provider' => 'groq',
            'model' => 'llama-3.3-70b-versatile',
            'is_active' => '1',
        ]);

        $fresh = $connection->fresh();
        $this->assertSame('Groq Renamed', $fresh->name);
        $this->assertSame('keep-me', $fresh->api_key, 'Blank key field must not wipe the saved key');
    }

    public function test_remove_api_key_checkbox_clears_it(): void
    {
        $user = $this->createUser();
        $connection = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'api_key' => 'remove-me',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('settings.ai.connections.save'), [
            'id' => $connection->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'remove_api_key' => '1',
            'is_active' => '1',
        ]);

        $this->assertNull($connection->fresh()->api_key);
    }

    public function test_delete_connection_cleans_up_assignments(): void
    {
        $user = $this->createUser();
        $connection = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Groq Main',
            'type' => 'text',
            'provider' => 'groq',
            'is_active' => true,
        ]);
        $connection->syncContentTypes(['video', 'shorts']);

        $response = $this->actingAs($user)->delete(route('settings.ai.connections.delete', $connection));

        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('ai_connections', ['id' => $connection->id]);
        $this->assertDatabaseCount('ai_connection_content_types', 0);
    }

    public function test_cannot_manage_another_users_connection(): void
    {
        $owner = $this->createUser();
        $intruder = $this->createUser();
        $connection = AiConnection::create([
            'user_id' => $owner->id,
            'name' => 'Owner Key',
            'type' => 'text',
            'provider' => 'groq',
            'is_active' => true,
        ]);

        $this->actingAs($intruder)->delete(route('settings.ai.connections.delete', $connection))->assertForbidden();

        $this->assertDatabaseHas('ai_connections', ['id' => $connection->id]);
    }

    public function test_pollinations_providers_resolve_with_gateway_defaults(): void
    {
        $manager = app(ProviderManager::class);
        $user = $this->createUser();

        // Text is OpenAI-compatible — same provider, gateway default base URL.
        $text = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Pollinations Text',
            'type' => 'text',
            'provider' => 'pollinations',
            'api_key' => 'sk_test_key',
            'is_active' => true,
        ]);
        $this->assertInstanceOf(OpenAITextProvider::class, $manager->make($text));
        $this->assertSame('https://gen.pollinations.ai/v1', $text->effectiveBaseUrl());
        $this->assertSame('openai', $text->effectiveModel());

        $image = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Pollinations Image',
            'type' => 'image',
            'provider' => 'pollinations_image',
            'api_key' => 'sk_test_key',
            'is_active' => true,
        ]);
        $this->assertInstanceOf(PollinationsImageProvider::class, $manager->make($image));
        $this->assertSame('https://gen.pollinations.ai', $image->effectiveBaseUrl());
        $this->assertSame('flux', $image->effectiveModel());

        $voice = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Pollinations Voice',
            'type' => 'voice',
            'provider' => 'pollinations_voice',
            'api_key' => 'sk_test_key',
            'is_active' => true,
        ]);
        $this->assertInstanceOf(PollinationsVoiceProvider::class, $manager->make($voice));
        $this->assertSame('https://gen.pollinations.ai', $voice->effectiveBaseUrl());
    }

    public function test_content_type_config_ignores_unowned_connection(): void
    {
        $owner = $this->createUser();
        $user = $this->createUser();
        $ownerConnection = AiConnection::create([
            'user_id' => $owner->id,
            'name' => 'Owner Text',
            'type' => 'text',
            'provider' => 'groq',
            'is_active' => true,
        ]);

        $this->actingAs($user)->post(route('settings.ai.content-types.save'), [
            'configs' => ['video' => ['text_primary' => $ownerConnection->id]],
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('ai_content_type_configs', 0);
    }
}
