<?php

namespace Tests\Unit;

use App\Services\Ai\SrtGenerator;
use PHPUnit\Framework\TestCase;

class SrtGeneratorTest extends TestCase
{
    private SrtGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SrtGenerator;
    }

    public function test_generates_standard_srt_with_proportional_word_timing(): void
    {
        $sentences = [
            ['text' => 'Octopuses have three hearts. And each one has a different job.', 'offset_ms' => 0, 'duration_ms' => 3000],
            ['text' => 'The second heart stops when they swim.', 'offset_ms' => 3000, 'duration_ms' => 2000],
        ];

        $path = sys_get_temp_dir().'/srt-test-'.uniqid().'.srt';
        $output = $this->generator->generate($sentences, $path);

        $this->assertFileExists($path);
        // 11 words over 3000ms at 5 words/line -> 3 lines, durations split
        // proportionally by word count (5/11 of 3000ms ≈ 1364ms each).
        $this->assertStringContainsString('00:00:00,000 --> 00:00:01,364', $output);
        $this->assertStringContainsString('00:00:02,727 --> 00:00:03,000', $output);
        $this->assertStringContainsString('Octopuses have three hearts. And', $output);
        $this->assertStringContainsString('job.', $output);
    }

    public function test_lines_have_at_most_five_words(): void
    {
        $sentences = [
            ['text' => 'One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen.', 'offset_ms' => 0, 'duration_ms' => 4000],
        ];

        $output = $this->generator->generate($sentences, sys_get_temp_dir().'/srt-test-'.uniqid().'.srt');

        $textLines = array_values(array_filter(explode("\n", $output), fn ($line) => preg_match('/^[0-9:,\.\s\-\>]+$/', $line) !== 1 && trim($line) !== '' && ! is_numeric($line)));
        foreach ($textLines as $line) {
            $this->assertLessThanOrEqual(5, str_word_count($line), "Caption line has too many words: {$line}");
        }
    }

    public function test_timing_is_distributed_proportionally_by_word_count(): void
    {
        $sentences = [
            ['text' => 'Alpha beta gamma delta.', 'offset_ms' => 0, 'duration_ms' => 1000],
        ];

        $output = $this->generator->generate($sentences, sys_get_temp_dir().'/srt-test-'.uniqid().'.srt');

        // 4 words / 1000ms -> single line 0 -> 1000ms.
        $this->assertStringContainsString('00:00:00,000 --> 00:00:01,000', $output);
    }

    public function test_strips_control_characters(): void
    {
        $sentences = [
            ['text' => "Clean\x00text here.", 'offset_ms' => 0, 'duration_ms' => 900],
        ];

        $output = $this->generator->generate($sentences, sys_get_temp_dir().'/srt-test-'.uniqid().'.srt');

        $this->assertStringNotContainsString("\x00", $output);
        $this->assertStringContainsString('Cleantext here.', $output);
    }
}
