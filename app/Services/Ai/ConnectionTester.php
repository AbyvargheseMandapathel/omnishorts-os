<?php

namespace App\Services\Ai;

use App\Models\AiConnection;
use App\Services\Ai\Contracts\ImageProvider;
use App\Services\Ai\Contracts\TextProvider;
use App\Services\Ai\Contracts\VoiceProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Throwable;

/**
 * One-click "Test Connection" for Settings → AI Connections. Builds the real
 * provider from the connection (as typed — the connection does not need to be
 * saved yet) and fires a tiny real request:
 *
 *   text  → one chat completion
 *   image → one small generated image
 *   voice → one short synthesized clip (deleted right after)
 *
 * Returns ['ok' => bool, 'message' => string]. Messages never contain API
 * keys or secrets.
 */
class ConnectionTester
{
    public function __construct(private readonly ProviderManager $manager) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(AiConnection $connection): array
    {
        try {
            $provider = $this->manager->make($connection);

            return match ($connection->type) {
                'text' => $this->testText($provider),
                'image' => $this->testImage($provider),
                'voice' => $this->testVoice($provider, $connection),
                default => ['ok' => false, 'message' => 'Unknown AI type.'],
            };
        } catch (AiProviderException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach the provider: '.$e->getMessage()];
        }
    }

    private function testText(TextProvider $provider): array
    {
        $response = $provider->complete(
            'You are a connectivity checker. Reply with exactly one word: OK',
            'Ping'
        );

        if (trim($response) === '') {
            return ['ok' => false, 'message' => 'The provider responded, but the reply was empty.'];
        }

        return ['ok' => true, 'message' => 'Connected — the text API responded.'];
    }

    private function testImage(ImageProvider $provider): array
    {
        $bytes = $provider->generate('a solid dark gray square, no text', 64, 64);

        if ($bytes === '') {
            return ['ok' => false, 'message' => 'The provider responded, but returned no image data.'];
        }

        return ['ok' => true, 'message' => 'Connected — generated a 64×64 test image ('.strlen($bytes).' bytes).'];
    }

    private function testVoice(VoiceProvider $provider, AiConnection $connection): array
    {
        $voice = $this->voiceFor($connection);
        $output = tempnam(sys_get_temp_dir(), 'ai-voice-test-');

        try {
            $result = $provider->synthesize('Connectivity test.', $voice, $output);

            return [
                'ok' => true,
                'message' => 'Connected — synthesized '.round($result->duration, 1).'s of test audio ('.count($result->sentences).' sentences).',
            ];
        } finally {
            if (is_string($output) && is_file($output)) {
                @unlink($output);
            }
        }
    }

    /**
     * A voice every provider accepts: Edge/Pollinations use short single-word
     * names from the config defaults, ElevenLabs uses its classic voice.
     */
    private function voiceFor(AiConnection $connection): string
    {
        $configVoice = $connection->config['voice'] ?? null;

        return (string) ($configVoice ?: match ($connection->provider) {
            'edge_tts' => config('ai.defaults.edge_tts.voice', 'en-US-ChristopherNeural'),
            'pollinations_voice' => config('ai.defaults.pollinations_voice.voice', 'nova'),
            'elevenlabs' => 'Rachel',
            default => 'en-US-ChristopherNeural',
        });
    }
}
