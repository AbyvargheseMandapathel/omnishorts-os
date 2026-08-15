<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class InstallScheduler extends Command
{
    protected $signature = 'cron:install';

    protected $description = 'Install the scheduler so scheduled publications auto-publish without any manual steps';

    public function handle(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->installWindowsTask();
        } else {
            $this->installCrontab();
        }

        return self::SUCCESS;
    }

    private function installWindowsTask(): void
    {
        $taskName = 'OmniShortsScheduler';
        $php = PHP_BINARY;
        $artisan = base_path('artisan');

        // Runs `php artisan schedule:run` every minute — same behavior as cron.
        $create = 'schtasks /Create /F /TN "' . $taskName . '" /SC MINUTE /MO 1 /TR "\"' . $php . '\" \"' . $artisan . '\" schedule:run"';

        $result = Process::run($create);

        if (!$result->successful()) {
            $this->error('Could not create the Windows Task automatically.');
            $this->info('Add it manually in Task Scheduler, or keep this running in a terminal: php artisan schedule:work');

            return;
        }

        Process::run('schtasks /Run /TN "' . $taskName . '"');

        $this->info('Windows Task "' . $taskName . '" installed and started.');
        $this->info('It runs every minute, forever, even after reboot. Scheduled reels will auto-publish.');
    }

    private function installCrontab(): void
    {
        $line = '* * * * * cd ' . base_path() . ' && ' . PHP_BINARY . ' artisan schedule:run >> /dev/null 2>&1';

        $existing = Process::run('crontab -l');

        if ($existing->successful() && str_contains($existing->output(), 'artisan schedule:run')) {
            $this->info('Cron entry already installed. Scheduled publications auto-publish every minute.');

            return;
        }

        $install = Process::run('(crontab -l 2>/dev/null; echo "' . $line . '") | crontab -');

        if ($install->successful()) {
            $this->info('Cron entry installed. Scheduled publications auto-publish every minute.');
            $this->line('Entry: ' . $line);
        } else {
            $this->error('Could not install the cron entry automatically. Add this line to crontab:');
            $this->line($line);
        }
    }
}
