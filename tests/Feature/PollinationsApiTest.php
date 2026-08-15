<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Providers\OpenAITextProvider;
use App\Services\Ai\Providers\PollinationsImageProvider;
use App\Services\Ai\Providers\PollinationsVoiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pollinations API contract tests.
 *
 * Deterministic tests fake the HTTP layer and pin the exact request/response
 * shapes. The live tests hit the real gateway (connectivity + documented
 * error shape) and only run when LIVE_AI_TESTS=1:
 *
 *   LIVE_AI_TESTS=1 php artisan test tests/Feature/PollinationsApiTest.php
 */
class PollinationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::stub(null); // never let a faked response leak between tests
    }

    private function user(): User
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        return $user;
    }

    private function connection(string $provider, string $type, ?string $key = 'sk_test_key'): AiConnection
    {
        return AiConnection::create([
            'user_id' => $this->user()->id,
            'name' => ucfirst(str_replace('_', ' ', $provider)),
            'type' => $type,
            'provider' => $provider,
            'api_key' => $key,
            'is_active' => true,
        ]);
    }

    private function liveTestsEnabled(): bool
    {
        return (bool) env('LIVE_AI_TESTS');
    }

    private function skipUnlessLive(): void
    {
        if (! $this->liveTestsEnabled()) {
            $this->markTestSkipped('Set LIVE_AI_TESTS=1 to hit the live Pollinations gateway.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Image — deterministic */
    /* ------------------------------------------------------------------ */

    public function test_image_success_returns_binary_bytes_and_sends_frame_params(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response('FAKE-IMAGE-BYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $connection = $this->connection('pollinations_image', 'image');
        $provider = app(PollinationsImageProvider::class);
        $provider->configure($connection);

        $bytes = $provider->generate('a cat in space', 720, 1280);

        $this->assertSame('FAKE-IMAGE-BYTES', $bytes);

        Http::assertSent(function (Request $request) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('Bearer sk_test_key', $request->header('Authorization')[0]);
            $this->assertStringContainsString('/image/a%20cat%20in%20space', $request->url());
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('flux', $query['model']);
            $this->assertSame('720', $query['width']);
            $this->assertSame('1280', $query['height']);

            return true;
        });
    }

    public function test_image_401_parses_the_real_error_message_shape(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response(
                '{"success":false,"error":{"message":"Authentication required. Please provide an API key via Authorization header (Bearer token) or ?key= query parameter.","code":"UNAUTHORIZED","timestamp":"2026-08-15T16:28:39.614Z"},"status":401}',
                401,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $provider = app(PollinationsImageProvider::class);
        $provider->configure($this->connection('pollinations_image', 'image'));

        try {
            $provider->generate('a cat', 720, 1280);
            $this->fail('Expected an AiProviderException for a 401.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('Authentication required', $e->getMessage());
            $this->assertFalse($e->transient, 'Rejected key is permanent — no fallback.');
        }
    }

    public function test_image_429_is_transient(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response(
                '{"success":false,"error":{"message":"Rate limited.","code":"RATE_LIMITED"},"status":429}',
                429
            ),
        ]);

        $provider = app(PollinationsImageProvider::class);
        $provider->configure($this->connection('pollinations_image', 'image'));

        try {
            $provider->generate('a cat', 720, 1280);
            $this->fail('Expected an AiProviderException for a 429.');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->transient, 'Rate limit should trigger the fallback.');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Voice — deterministic (key optional — endpoint is anonymous today) */
    /* ------------------------------------------------------------------ */

    public function test_voice_success_without_key_still_works(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response('FAKE-MP3-BYTES', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $provider = app(PollinationsVoiceProvider::class);
        $provider->configure($this->connection('pollinations_voice', 'voice', null));

        $outputPath = storage_path('app/ai/pollinations-test-'.uniqid().'.mp3');
        $result = $provider->synthesize('Hello world. This is a test.', 'en-US-ChristopherNeural', $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertSame('FAKE-MP3-BYTES', file_get_contents($outputPath));
        $this->assertGreaterThan(0.1, $result->duration);
        $this->assertNotEmpty($result->sentences, 'Sentence timing must be estimated when the API gives none.');
        $this->assertSame('Hello world. This is a test.', implode(' ', array_column($result->sentences, 'text')));

        Http::assertSent(function (Request $request) {
            $this->assertFalse($request->hasHeader('Authorization'), 'Anonymous audio must not send a key header.');

            return true;
        });

        unlink($outputPath);
    }

    public function test_voice_sends_key_when_configured_and_uses_connection_voice(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response('FAKE-MP3-BYTES', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $connection = $this->connection('pollinations_voice', 'voice');
        $connection->config = ['voice' => 'alloy'];
        $connection->save();

        $provider = app(PollinationsVoiceProvider::class);
        $provider->configure($connection);

        $outputPath = storage_path('app/ai/pollinations-test-'.uniqid().'.mp3');
        $provider->synthesize('Hello.', 'en-US-ChristopherNeural', $outputPath);

        Http::assertSent(function (Request $request) {
            $this->assertSame('Bearer sk_test_key', $request->header('Authorization')[0]);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('alloy', $query['voice'], 'Connection config voice must override the Edge-shaped default.');

            return true;
        });

        unlink($outputPath);
    }

    /* ------------------------------------------------------------------ */
    /* Text — deterministic (OpenAI-compatible, key optional today) */
    /* ------------------------------------------------------------------ */

    public function test_text_success_hits_gateway_without_key(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response(
                '{"choices":[{"message":{"content":"{\"title\":\"T\",\"description\":\"D\",\"narration\":\"N\",\"scenes\":[]}"}}]}',
                200
            ),
        ]);

        $provider = app(OpenAITextProvider::class);
        $provider->configure($this->connection('pollinations', 'text', null));

        $content = $provider->complete('system', 'user');

        $this->assertStringContainsString('"title":"T"', $content);

        Http::assertSent(function (Request $request) {
            $this->assertSame('https://gen.pollinations.ai/v1/chat/completions', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertFalse($request->hasHeader('Authorization'));

            return true;
        });
    }

    public function test_text_401_surfaces_rejected_key_error(): void
    {
        Http::fake([
            'gen.pollinations.ai/*' => Http::response('{"error":{"message":"bad key"}}', 401),
        ]);

        $provider = app(OpenAITextProvider::class);
        $provider->configure($this->connection('pollinations', 'text'));

        try {
            $provider->complete('system', 'user');
            $this->fail('Expected an AiProviderException for a 401.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('rejected the API key', $e->getMessage());
            $this->assertFalse($e->transient);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Live connectivity — opt-in via LIVE_AI_TESTS=1 */
    /* ------------------------------------------------------------------ */

    public function test_live_models_endpoint_reachable_without_auth(): void
    {
        $this->skipUnlessLive();

        $response = Http::timeout(25)->get('https://gen.pollinations.ai/v1/models');

        $this->assertSame(200, $response->status());
        $this->assertSame('list', $response->json('object'));
        $this->assertNotEmpty($response->json('data'), 'Model registry must list models.');
    }

    public function test_live_image_requires_auth_with_documented_error_shape(): void
    {
        $this->skipUnlessLive();

        $response = Http::timeout(40)
            ->withToken('sk_definitely_bad_probe_key')
            ->get('https://gen.pollinations.ai/image/a%20cat?model=flux&width=128&height=224');

        $this->assertSame(401, $response->status());
        $this->assertTrue($response->json('success') === false);
        $this->assertIsString($response->json('error.message'));
        $this->assertIsString($response->json('error.code'));
    }

    public function test_live_audio_returns_mpeg_without_auth(): void
    {
        $this->skipUnlessLive();

        $response = Http::timeout(40)
            ->accept('audio/*')
            ->get('https://gen.pollinations.ai/audio/Hello%20world?voice=nova');

        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('audio', (string) $response->header('Content-Type'));
        $this->assertGreaterThan(1000, strlen($response->body()), 'Audio must be a real MP3, not an error body.');
    }

    public function test_live_chat_returns_completions_without_auth(): void
    {
        $this->skipUnlessLive();

        $response = Http::timeout(40)->post('https://gen.pollinations.ai/v1/chat/completions', [
            'model' => 'openai',
            'messages' => [['role' => 'user', 'content' => 'Say hello in one word.']],
        ]);

        $this->assertSame(200, $response->status());
        $content = $response->json('choices.0.message.content');
        $this->assertIsString($content);
        $this->assertNotSame('', trim($content));
    }
}
