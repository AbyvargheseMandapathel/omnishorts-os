<?php

namespace Tests\Unit;

use App\Models\AiVideoJob;
use App\Models\Channel;
use App\Models\User;
use App\Services\Ai\SceneTimingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SceneTimingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $scenes, array $voice): AiVideoJob
    {
        $user = User::factory()->create();
        $channel = Channel::create(['user_id' => $user->id, 'name' => 'Test Channel']);

        return AiVideoJob::create([
            'user_id' => $user->id,
            'channel_id' => $channel->id,
            'content_type' => 'video',
            'topic' => 'test',
            'background_path' => 'x.mp4',
            'status' => 'queued',
            'scenes' => $scenes,
            'voice' => $voice,
        ]);
    }

    public function test_uses_real_narration_timing_when_available(): void
    {
        $scenes = [
            ['scene_number' => 1, 'narration' => 'The first scene talks about octopuses.'],
            ['scene_number' => 2, 'narration' => 'Then the second scene discusses their hearts.'],
            ['scene_number' => 3, 'narration' => 'Finally the third scene wraps it all up.'],
        ];
        $voice = [
            'duration' => 6.0,
            'sentences' => [
                ['text' => 'The first scene talks about octopuses.', 'offset_ms' => 0, 'duration_ms' => 2000],
                ['text' => 'Then the second scene discusses their hearts.', 'offset_ms' => 2000, 'duration_ms' => 2000],
                ['text' => 'Finally the third scene wraps it all up.', 'offset_ms' => 4000, 'duration_ms' => 2000],
            ],
        ];

        $job = $this->job($scenes, $voice);
        app(SceneTimingCalculator::class)->calculate($job);

        // Scene timings round-trip through a JSON column, so whole seconds may
        // come back as ints — compare loosely.
        $timed = $job->fresh()->scenes;

        $this->assertEquals(0.0, $timed[0]['start_time']);
        $this->assertEquals(2.0, $timed[0]['end_time']);
        $this->assertEquals(2.0, $timed[1]['start_time']);
        $this->assertEquals(4.0, $timed[1]['end_time']);
        $this->assertEquals(4.0, $timed[2]['start_time']);
        $this->assertEquals(6.0, $timed[2]['end_time']);
    }

    public function test_does_not_divide_duration_equally_when_timing_exists(): void
    {
        // Uneven sentence lengths — scenes must follow the actual timings.
        $scenes = [
            ['scene_number' => 1, 'narration' => 'Short first bit.'],
            ['scene_number' => 2, 'narration' => 'A much longer second scene that keeps talking for a while and says more words.'],
        ];
        $voice = [
            'duration' => 5.0,
            'sentences' => [
                ['text' => 'Short first bit.', 'offset_ms' => 0, 'duration_ms' => 1000],
                ['text' => 'A much longer second scene that keeps talking for a while and says more words.', 'offset_ms' => 1000, 'duration_ms' => 4000],
            ],
        ];

        $job = $this->job($scenes, $voice);
        app(SceneTimingCalculator::class)->calculate($job);

        $timed = $job->fresh()->scenes;

        $this->assertEquals(0.0, $timed[0]['start_time']);
        $this->assertEquals(1.0, $timed[0]['end_time'], 'Scene 1 must end when its narration ends, not at the halfway point');
        $this->assertEquals(1.0, $timed[1]['start_time']);
        $this->assertEquals(5.0, $timed[1]['end_time']);
    }

    public function test_falls_back_to_proportional_when_no_timing(): void
    {
        $scenes = [
            ['scene_number' => 1, 'narration' => 'One.'],
            ['scene_number' => 2, 'narration' => 'Two.'],
            ['scene_number' => 3, 'narration' => 'Three.'],
        ];
        $voice = ['duration' => 9.0, 'sentences' => []];

        $job = $this->job($scenes, $voice);
        app(SceneTimingCalculator::class)->calculate($job);

        $timed = $job->fresh()->scenes;

        $this->assertEquals(0.0, $timed[0]['start_time']);
        $this->assertEquals(3.0, $timed[0]['end_time']);
        $this->assertEquals(3.0, $timed[1]['start_time']);
        $this->assertEquals(6.0, $timed[2]['start_time']);
        $this->assertEquals(9.0, $timed[2]['end_time']);
    }

    public function test_multiple_sentences_per_scene_are_aggregated(): void
    {
        $scenes = [
            ['scene_number' => 1, 'narration' => 'First scene has two spoken sentences. And a second sentence too.'],
            ['scene_number' => 2, 'narration' => 'Second scene.'],
        ];
        $voice = [
            'duration' => 5.0,
            'sentences' => [
                ['text' => 'First scene has two spoken sentences.', 'offset_ms' => 0, 'duration_ms' => 2000],
                ['text' => 'And a second sentence too.', 'offset_ms' => 2000, 'duration_ms' => 1000],
                ['text' => 'Second scene.', 'offset_ms' => 3000, 'duration_ms' => 2000],
            ],
        ];

        $job = $this->job($scenes, $voice);
        app(SceneTimingCalculator::class)->calculate($job);

        $timed = $job->fresh()->scenes;

        $this->assertEquals(0.0, $timed[0]['start_time']);
        $this->assertEquals(3.0, $timed[0]['end_time'], 'Scene 1 spans both of its sentences');
        $this->assertEquals(3.0, $timed[1]['start_time']);
        $this->assertEquals(5.0, $timed[1]['end_time']);
    }
}
