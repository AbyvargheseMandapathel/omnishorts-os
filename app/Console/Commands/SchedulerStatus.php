<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SchedulerStatus extends Command
{
    protected $signature = 'cron:status';

    protected $description = 'Show whether the OS scheduler is installed and which cron jobs are enabled';

    public function handle(): int
    {
        $this->line('OS: '.PHP_OS_FAMILY);

        if (PHP_OS_FAMILY === 'Windows') {
            $query = Process::run('schtasks /Query /TN "OmniShortsScheduler" /FO LIST');
            if ($query->successful() && preg_match('/Status:\s+(.+)/', $query->output(), $m)) {
                $this->line('Scheduler task: OmniShortsScheduler — '.trim($m[1]));
            } else {
                $this->warn('Scheduler task: NOT installed (run php artisan cron:install)');
            }
        } else {
            $existing = Process::run('crontab -l');
            if ($existing->successful() && str_contains($existing->output(), 'artisan schedule:run')) {
                $this->line('Scheduler entry: installed in crontab (every minute)');
            } else {
                $this->warn('Scheduler entry: NOT installed (run php artisan cron:install)');
            }
        }

        $this->line('Last heartbeat: '.(Setting::get('cron.last_checked') ?? 'never'));
        $this->line('Auto-publish enabled: '.(Setting::get('cron.enabled', '1') === '1' ? 'yes' : 'no'));
        $this->line('  process-due (every minute): '.(Setting::get('cron.publish_enabled', '1') === '1' ? 'on' : 'off'));
        $this->line('  analytics:refresh (twice daily, 08:00 & 20:00): '.(Setting::get('cron.analytics_enabled', '1') === '1' ? 'on' : 'off'));
        $this->line('  videos:prune-files (daily): '.(Setting::get('cron.prune_enabled', '1') === '1' ? 'on' : 'off'));

        return self::SUCCESS;
    }
}
