<?php

namespace App\Services\Ai;

use App\Models\AiVideoJob;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Composes the final video with FFmpeg: background video (looped or trimmed
 * to the narration length) + scene images overlaid at their narration
 * timings + the generated voice-over + burned-in captions.
 *
 * PHP only orchestrates; ffmpeg does the frame work. The command is passed
 * to proc_open as an argument array (no shell), and every path is ours —
 * there is no user-controlled input in the filter graph, so no injection.
 */
class VideoRenderer
{
    private static ?bool $subtitlesSupported = null;

    /**
     * Render the job's final video. Returns the video-disk path of the result.
     *
     * @throws AiProviderException
     */
    public function render(AiVideoJob $job): string
    {
        $ffmpeg = (string) config('ai.ffmpeg_binary');
        if (! $this->binaryExists($ffmpeg)) {
            throw AiProviderException::permanent(
                'ffmpeg is not available on this server — the render stage needs it. '
                .'Install ffmpeg or point FFMPEG_BINARY at a binary.'
            );
        }

        $workDir = storage_path('app/ai/'.$job->workDir());
        $backgroundLocal = $workDir.'/background_src.mp4';
        if (! is_file($backgroundLocal)) {
            throw AiProviderException::permanent('Background video is missing from the working directory.');
        }

        $duration = (float) ($job->voice['duration'] ?? 0);
        if ($duration <= 0) {
            throw AiProviderException::permanent('Cannot render without narration audio.');
        }

        $scenes = collect($job->scenes ?? []);
        $imageInputs = [];
        foreach ($scenes as $scene) {
            // image_path is stored relative to the ai disk root (jobs/{id}/...).
            $path = storage_path('app/ai/'.ltrim((string) ($scene['image_path'] ?? ''), '/'));
            if (! is_file($path)) {
                throw AiProviderException::permanent('Scene image missing for scene #'.($scene['scene_number'] ?? '?').' — regenerate the images first.');
            }
            $imageInputs[] = $path;
        }

        $voiceLocal = $workDir.'/voice/narration.mp3';
        if (! is_file($voiceLocal)) {
            throw AiProviderException::permanent('Narration audio is missing — regenerate the voice first.');
        }

        $width = (int) ($job->background_width ?: 720);
        $height = (int) ($job->background_height ?: 1280);

        // Background shorter than narration -> loop; longer -> trim to duration.
        $loop = $job->background_duration !== null && $job->background_duration > 0 && $job->background_duration < $duration;

        $command = [$ffmpeg, '-y', '-hide_banner', '-loglevel', 'error'];
        if ($loop) {
            $command[] = '-stream_loop';
            $command[] = '-1';
        }
        $command[] = '-i';
        $command[] = $backgroundLocal;

        foreach ($imageInputs as $image) {
            $command[] = '-i';
            $command[] = $image;
        }
        $command[] = '-i';
        $command[] = $voiceLocal;

        $filter = $this->buildFilterGraph($job, $imageInputs, $width, $height, $workDir);
        $command[] = '-filter_complex';
        $command[] = $filter;

        $command[] = '-map';
        $command[] = '[vout]';
        $command[] = '-map';
        $command[] = '[aout]';
        $command[] = '-c:v';
        $command[] = (string) config('ai.video_codec');
        $command[] = '-preset';
        $command[] = (string) config('ai.preset');
        $command[] = '-crf';
        $command[] = (string) config('ai.crf');
        $command[] = '-r';
        $command[] = (string) config('ai.fps');
        $command[] = '-c:a';
        $command[] = (string) config('ai.audio_codec');
        $command[] = '-b:a';
        $command[] = '128k';
        $command[] = '-pix_fmt';
        $command[] = 'yuv420p';
        $command[] = '-movflags';
        $command[] = '+faststart';
        $command[] = '-t';
        $command[] = number_format($duration, 3, '.', '');
        $command[] = $workDir.'/final.mp4';

        $this->runProcess($command, $workDir, (int) config('ai.render_timeout'));

        // The finished file becomes a normal library video on the video disk.
        $finalPath = 'videos/ai-'.$job->id.'.mp4';
        $this->copyToVideoDisk($workDir.'/final.mp4', $finalPath);

        $job->update(['final_path' => $finalPath]);

        return $finalPath;
    }

    /**
     * Build the filter_complex graph: scale each image, overlay it for its
     * scene window, burn captions, and mix the narration audio.
     */
    private function buildFilterGraph(AiVideoJob $job, array $imageInputs, int $width, int $height, string $workDir): string
    {
        $filters = [];
        $scenes = $job->scenes ?? [];

        // Images -> scaled, centered, yuv420p.
        $last = '[0:v]';
        foreach ($scenes as $i => $scene) {
            $input = '['.($i + 1).':v]';
            $filters[] = "{$input}scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},format=yuv420p[img{$i}]";
            $enable = sprintf('between(t,%.3f,%.3f)', (float) $scene['start_time'], (float) $scene['end_time']);
            $label = $i === count($scenes) - 1 ? 'vfinal' : 'v'.$i;
            $filters[] = "{$last}[img{$i}]overlay=0:0:enable='{$enable}'[{$label}]";
            $last = '['.$label.']';
        }

        // Burn captions when the ffmpeg build supports the subtitles filter.
        $captionsAbsolute = $job->captions_path ? storage_path('app/ai/'.ltrim($job->captions_path, '/')) : null;
        if ($this->subtitlesSupported() && $captionsAbsolute && is_file($captionsAbsolute)) {
            $srtRelative = $this->relativePath($workDir, $captionsAbsolute);
            $filters[] = "{$last}subtitles='{$srtRelative}':force_style='Fontsize=22,Alignment=2,MarginV=36'[vout]";
        } else {
            $filters[] = "{$last}null[vout]";
        }

        // Narration is the primary audio; the background audio is mute by
        // default, ducked to 15% when kept, or 30% when reduced.
        $audioMode = (string) ($job->progress['audio_mode'] ?? 'mute');
        $voiceInput = '['.(count($imageInputs) + 1).':a]';
        if ($audioMode === 'mute') {
            $filters[] = "{$voiceInput}anull[aout]";
        } else {
            $bgVolume = $audioMode === 'keep' ? '0.15' : '0.3';
            $filters[] = "[0:a]volume={$bgVolume}[bgd];[bgd]{$voiceInput}amix=inputs=2:duration=first:normalize=0[aout]";
        }

        return implode(';', $filters);
    }

    private function relativePath(string $fromDir, string $path): string
    {
        $from = str_replace('\\', '/', $fromDir);
        $to = str_replace('\\', '/', $path);
        if (str_starts_with($to, $from.'/')) {
            return substr($to, strlen($from) + 1);
        }

        return $to;
    }

    private function subtitlesSupported(): bool
    {
        if (self::$subtitlesSupported !== null) {
            return self::$subtitlesSupported;
        }

        try {
            $ffmpeg = (string) config('ai.ffmpeg_binary');
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open([$ffmpeg, '-hide_banner', '-filters'], $descriptors, $pipes);
            $output = is_resource($process)
                ? stream_get_contents($pipes[1]).stream_get_contents($pipes[2])
                : '';
            if (is_resource($process)) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }

            self::$subtitlesSupported = str_contains($output, ' subtitles ');
        } catch (\Throwable) {
            self::$subtitlesSupported = false;
        }

        return self::$subtitlesSupported;
    }

    private function copyToVideoDisk(string $localPath, string $finalPath): void
    {
        $disk = (string) config('filesystems.video_disk', 'public');
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new AiProviderException('Could not read the rendered video for storage.');
        }

        try {
            $written = Storage::disk($disk)->writeStream($finalPath, $stream);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw new AiProviderException("Rendered video could not be saved to the \"{$disk}\" disk — check disk configuration.");
        }
    }

    private function runProcess(array $command, string $cwd, int $timeout): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (! is_resource($process)) {
            throw new AiProviderException('Could not start ffmpeg.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $running = true;

        while (microtime(true) < $deadline) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (! $status['running']) {
                $running = false;
                break;
            }
            usleep(150_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($running) {
            proc_terminate($process, 9);
            proc_close($process);

            throw new AiProviderException('ffmpeg timed out after '.$timeout.'s — the video may be too long for this server.');
        }

        $exit = proc_close($process);
        if ($exit !== 0) {
            $detail = Str::limit(trim($stderr) ?: trim($stdout), 500);

            throw new AiProviderException('ffmpeg failed (exit '.$exit.'): '.($detail ?: 'unknown error'));
        }
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
}
