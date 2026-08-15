<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat runs unconditionally so the dashboard can always tell whether
// the OS scheduler itself is alive (independent of which jobs are enabled).
Schedule::call(function () {
    Setting::set('cron.last_checked', now()->toDateTimeString());
})->everyMinute()->name('cron-heartbeat');

// Each job can be switched off from Settings (master cron.enabled toggle
// plus a per-job toggle). The guards live at the SCHEDULE level so manual
// runs (dashboard Run Now / Refresh All, CLI) keep working even when the
// automatic run is disabled.
Schedule::command('publications:process-due')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => Setting::get('cron.enabled', '1') === '1' && Setting::get('cron.publish_enabled', '1') === '1');

// AI video generation pipeline — one queued job per tick (jobs are long).
Schedule::command('ai:process-jobs')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => Setting::get('cron.enabled', '1') === '1');

// Hands-free daily AI video: picks the day's topic, generates the whole reel
// (black background when none is configured), and auto-approves it into the
// Content Library. Internal guards run it at most once per day.
Schedule::command('ai:generate-daily')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => Setting::get('cron.enabled', '1') === '1');

// Keep the hosting disk bounded: drop video files that are no longer needed.
Schedule::command('videos:prune-files')
    ->daily()
    ->when(fn () => Setting::get('cron.enabled', '1') === '1' && Setting::get('cron.prune_enabled', '1') === '1');

// Keep real view/like/comment/share stats fresh for published reels. Twice
// daily (08:00 & 20:00) keeps YouTube API hits low — right-after-upload
// fetches plus the manual Refresh buttons cover the rest.
Schedule::command('analytics:refresh')
    ->twiceDaily(8, 20)
    ->when(fn () => Setting::get('cron.enabled', '1') === '1' && Setting::get('cron.analytics_enabled', '1') === '1');
