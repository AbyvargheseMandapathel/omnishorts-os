<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class UninstallScheduler extends Command
{
    protected $signature = 'cron:uninstall';

    protected $description = 'Remove the OS-level scheduler entry (Windows Task / crontab line)';

    public function handle(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->removeWindowsTask();
        } else {
            $this->removeCrontabLine();
        }

        return self::SUCCESS;
    }

    private function removeWindowsTask(): void
    {
        $taskName = 'OmniShortsScheduler';

        $result = Process::run('schtasks /Delete /F /TN "'.$taskName.'"');

        if ($result->successful()) {
            $this->info('Windows Task "'.$taskName.'" removed.');
        } else {
            $this->warn('No Windows Task "'.$taskName.'" found (or it could not be removed).');
        }
    }

    private function removeCrontabLine(): void
    {
        $existing = Process::run('crontab -l');

        if (! $existing->successful() || ! str_contains($existing->output(), 'artisan schedule:run')) {
            $this->warn('No OmniShorts cron entry found in crontab.');

            return;
        }

        $kept = collect(explode("\n", $existing->output()))
            ->reject(fn ($line) => str_contains($line, 'artisan schedule:run'))
            ->implode("\n");

        $install = Process::run('printf "%s" '.escapeshellarg($kept).' | crontab -');

        if ($install->successful()) {
            $this->info('OmniShorts cron entry removed from crontab.');
        } else {
            $this->error('Could not update crontab automatically. Remove the line manually:');
            $this->line('* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1');
        }
    }
}
