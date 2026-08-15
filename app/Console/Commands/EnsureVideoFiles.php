<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\PlaceholderVideo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Repair tool for reels whose upload silently failed (e.g. a misconfigured FTP
 * disk): every video with no file — or whose file is gone from the disk — gets
 * a real, playable placeholder MP4 so the app stops showing file-less reels.
 * Also wired into public/setup.php as a post-deploy step.
 */
class EnsureVideoFiles extends Command
{
    protected $signature = 'videos:ensure-files {--dry-run : Report what would be repaired without writing anything}';

    protected $description = 'Generate a placeholder video file for reels that have no file (failed uploads)';

    public function handle(): int
    {
        $diskName = (string) config('filesystems.video_disk', 'public');
        $disk = Storage::disk($diskName);

        $videos = Video::all()->filter(function (Video $video) use ($disk) {
            return ! $video->file_path || ! $disk->exists($video->file_path);
        });

        if ($videos->isEmpty()) {
            $this->info('All videos have a file on the "'.$diskName.'" disk.');

            return self::SUCCESS;
        }

        $this->info("{$videos->count()} video(s) have no file on the \"{$diskName}\" disk.");

        if ($this->option('dry-run')) {
            foreach ($videos as $video) {
                $this->line("  [dry-run] would create a placeholder for #{$video->id} — {$video->title}");
            }

            return self::SUCCESS;
        }

        $created = 0;
        $scheduledPlaceholders = 0;

        foreach ($videos as $video) {
            $path = 'videos/placeholder-'.$video->id.'.mp4';

            try {
                if ($disk->put($path, PlaceholderVideo::mp4()) === false) {
                    $this->error("  Failed to write placeholder for #{$video->id} — is the video disk writable? (see /health)");
                    continue;
                }
            } catch (Throwable $e) {
                $this->error("  Failed to write placeholder for #{$video->id}: {$e->getMessage()}");
                continue;
            }

            $video->update([
                'file_path' => $path,
                'duration' => PlaceholderVideo::DURATION_SECONDS,
            ]);

            $created++;

            if ($video->publications()->where('status', 'scheduled')->exists()) {
                $scheduledPlaceholders++;
            }
        }

        if ($created === 0) {
            $this->error('No placeholders could be written — fix the video disk first (see /health).');

            return self::FAILURE;
        }

        $this->info("Created {$created} placeholder file(s) — reels now have a playable (black) file.");

        if ($scheduledPlaceholders > 0) {
            $this->warn("WARNING: {$scheduledPlaceholders} of these reels have scheduled publications — the black placeholder will be uploaded to YouTube unless you re-upload the real file or cancel those posts first.");
        }

        return self::SUCCESS;
    }
}
