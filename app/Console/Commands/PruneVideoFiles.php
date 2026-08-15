<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneVideoFiles extends Command
{
    protected $signature = 'videos:prune-files
        {--retention-days=14 : Delete files of videos whose last publish was more than this many days ago}
        {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete video files that are no longer needed (published past retention, or orphaned) so storage never fills up';

    public function handle(): int
    {
        Setting::set('cron.last_checked', now()->toDateTimeString());
        Setting::set('cron.last_run.prune', now()->toDateTimeString());

        $diskName = (string) config('filesystems.video_disk', 'public');
        $storage = Storage::disk($diskName);
        $dryRun = (bool) $this->option('dry-run');
        $retentionDays = (int) $this->option('retention-days');
        $cutoff = now()->subDays($retentionDays);

        $deleted = 0;

        // 1) Videos published longer ago than the retention window — their
        //    files are no longer needed for publishing.
        $expired = Video::query()
            ->whereNotNull('file_path')
            ->whereHas('publications', fn ($q) => $q->where('status', 'published')->where('published_at', '<=', $cutoff))
            ->whereDoesntHave('publications', fn ($q) => $q->where('status', 'published')->where('published_at', '>', $cutoff))
            ->get();

        foreach ($expired as $video) {
            if ($this->deleteFile($storage, $video->file_path, $dryRun)) {
                $deleted++;
                $video->update(['file_path' => null]);
            }
        }

        // 2) Orphaned files — no video row references them (e.g. rows deleted
        //    before the cleanup above existed, or failed uploads).
        $known = Video::whereNotNull('file_path')->pluck('file_path')->flip();
        foreach ($storage->allFiles('videos') as $path) {
            if ($known->has($path)) {
                continue;
            }
            if ($this->deleteFile($storage, $path, $dryRun)) {
                $deleted++;
            }
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$deleted} video file(s) from the '{$diskName}' disk.");

        return self::SUCCESS;
    }

    private function deleteFile($storage, string $path, bool $dryRun): bool
    {
        if ($dryRun) {
            $this->line("  [dry-run] {$path}");

            return false;
        }

        if (! $storage->exists($path)) {
            return false;
        }

        $storage->delete($path);
        $this->line("  deleted {$path}");

        return true;
    }
}
