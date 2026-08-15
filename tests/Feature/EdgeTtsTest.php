<?php

namespace Tests\Feature;

use App\Models\AiConnection;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\Providers\EdgeTtsVoiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Edge TTS is the free voice provider — no API key. It speaks to Microsoft's
 * WebSocket endpoint directly (the same thing the Python edge-tts library
 * does), so the protocol must stay byte-compatible with the reference.
 *
 * The live test hits the real endpoint and only runs when LIVE_AI_TESTS=1:
 *
 *   LIVE_AI_TESTS=1 php artisan test tests/Feature/EdgeTtsTest.php
 */
class EdgeTtsTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): EdgeTtsVoiceProvider
    {
        $user = User::factory()->create();
        Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        $connection = AiConnection::create([
            'user_id' => $user->id,
            'name' => 'Edge TTS',
            'type' => 'voice',
            'provider' => 'edge_tts',
            'api_key' => null, // Edge TTS never needs a key
            'is_active' => true,
        ]);

        return (new EdgeTtsVoiceProvider)->configure($connection);
    }

    private function secMsGec(int $unixTime): string
    {
        return (new ReflectionMethod(EdgeTtsVoiceProvider::class, 'secMsGec'))->invoke($this->provider(), $unixTime);
    }

    public function test_sec_ms_gec_matches_reference_algorithm(): void
    {
        // Golden value: SHA-256 of (FILETIME seconds rounded down to 300s)
        // × 10⁷ concatenated with the trusted client token, uppercased.
        // Verified live — the endpoint accepted this exact token.
        $this->assertSame(
            '3A2BEF3B700C5F1D2B493CB87A79E6C26DD8DA08584494AE8BCCBE49249469C1',
            $this->secMsGec(1755000000)
        );
    }

    public function test_sec_ms_gec_is_stable_within_300_second_window(): void
    {
        $first = $this->secMsGec(1755000000);
        $this->assertSame($first, $this->secMsGec(1755000099), 'Same 300s window must mint the same token.');
        $this->assertNotSame($first, $this->secMsGec(1755000300), 'Next window must mint a fresh token.');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{64}$/', $first);
    }

    public function test_live_edge_tts_synthesizes_with_real_sentence_timing(): void
    {
        if (! env('LIVE_AI_TESTS')) {
            $this->markTestSkipped('Set LIVE_AI_TESTS=1 to hit the live Edge TTS WebSocket.');
        }

        $out = storage_path('app/ai/edge-tts-test-'.uniqid().'.mp3');

        try {
            $result = $this->provider()->synthesize(
                'Octopuses have three hearts. Two of them pump blood to the gills.',
                'en-US-ChristopherNeural',
                $out
            );

            $this->assertFileExists($out);
            $this->assertGreaterThan(1000, filesize($out), 'A real MP3 must be generated.');
            $this->assertCount(2, $result->sentences, 'Two punctuated sentences with real timing.');
            $this->assertStringContainsString('.', $result->sentences[0]['text'], 'Punctuation must survive.');
            $this->assertGreaterThan(0, $result->sentences[0]['offset_ms']);
            $this->assertGreaterThan(0, $result->sentences[0]['duration_ms']);
            $this->assertGreaterThan(1.0, $result->duration);
        } finally {
            @unlink($out);
        }
    }
}
