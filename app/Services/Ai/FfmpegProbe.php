<?php

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\VideoProbe;

/**
 * Reads duration / resolution / fps from a local media file using ffprobe.
 * Falls back to getID3 (already used by the app) for duration when ffprobe
 * is unavailable, so the pipeline still works without a full ffmpeg install
 * until the render stage (which genuinely needs it).
 *
 * @return array{duration: float|null, width: int|null, height: int|null, fps: float|null}
 */
class FfmpegProbe
{
    public function probe(string $filePath): array
    {
        $ffprobe = (string) config('ai.ffprobe_binary');
        if (! $this->binaryExists($ffprobe)) {
            return $this->fallback($filePath);
        }

        try {
            $output = $this->run([$ffprobe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $filePath]);
        } catch (\Throwable) {
            return $this->fallback($filePath);
        }

        $data = json_decode($output, true);
        if (! is_array($data)) {
            return $this->fallback($filePath);
        }

        $videoStream = collect($data['streams'] ?? [])->first(fn ($s) => ($s['codec_type'] ?? '') === 'video');

        $duration = isset($data['format']['duration']) ? (float) $data['format']['duration'] : null;
        $fps = $this->parseFps($videoStream['avg_frame_rate'] ?? null);

        return [
            'duration' => $duration,
            'width' => isset($videoStream['width']) ? (int) $videoStream['width'] : null,
            'height' => isset($videoStream['height']) ? (int) $videoStream['height'] : null,
            'fps' => $fps,
        ];
    }

    private function fallback(string $filePath): array
    {
        $duration = null;
        try {
            $duration = app(VideoProbe::class)->durationSeconds($filePath);
        } catch (\Throwable) {
            // no duration available
        }

        return ['duration' => $duration, 'width' => null, 'height' => null, 'fps' => null];
    }

    private function parseFps(?string $avgFrameRate): ?float
    {
        if (! $avgFrameRate || ! str_contains($avgFrameRate, '/')) {
            return null;
        }

        [$num, $den] = array_map('floatval', explode('/', $avgFrameRate, 2));
        if ($den <= 0 || $num <= 0) {
            return null;
        }

        return round($num / $den, 3);
    }

    private function binaryExists(string $binary): bool
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) {
            return is_file($binary);
        }

        // Static probe of the PATH — no user input, so no injection surface.
        $found = DIRECTORY_SEPARATOR === '\\'
            ? shell_exec('where '.escapeshellarg($binary).' 2>nul')
            : shell_exec('command -v '.escapeshellarg($binary).' 2>/dev/null');

        return is_string($found) && trim($found) !== '';
    }

    private function run(array $command): string
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new AiProviderException('Could not start ffprobe.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new AiProviderException('ffprobe failed: '.mb_substr(trim((string) $stderr), 0, 300));
        }

        return (string) $stdout;
    }
}
