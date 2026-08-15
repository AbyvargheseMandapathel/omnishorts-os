<?php

namespace Tests\Fakes;

use App\Services\Ai\Contracts\TextProvider;
use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Deterministic text provider for tests. Each complete() call shifts one
 * response off the queue; default canned JSON matches the script schema.
 */
class FakeTextProvider implements TextProvider
{
    /** @var array<int, string|\Throwable> */
    public array $responses = [];

    public int $calls = 0;

    public function complete(string $systemPrompt, string $userPrompt, array $config = []): string
    {
        $this->calls++;

        if ($this->responses === []) {
            return $this->defaultScript($this->scenesFromPrompt($systemPrompt));
        }

        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return (string) $next;
    }

    private function scenesFromPrompt(string $systemPrompt): int
    {
        if (preg_match('/EXACTLY (\d+) scenes/', $systemPrompt, $m)) {
            return (int) $m[1];
        }

        return 5;
    }

    public function defaultScript(int $scenes = 5): string
    {
        $sceneList = [];
        for ($i = 1; $i <= $scenes; $i++) {
            $sceneList[] = [
                'scene_number' => $i,
                'narration' => "Scene {$i} narration with enough words to speak.",
                'image_prompt' => "A photorealistic scene {$i} with dramatic lighting.",
            ];
        }

        return json_encode([
            'title' => 'Test Video Title',
            'description' => 'A test description for the generated video.',
            'narration' => implode(' ', array_column($sceneList, 'narration')),
            'scenes' => $sceneList,
        ]);
    }

    public static function permanent(string $message = 'bad key'): AiProviderException
    {
        return AiProviderException::permanent($message);
    }

    public static function transient(string $message = 'rate limited'): AiProviderException
    {
        return new AiProviderException($message, true);
    }
}
