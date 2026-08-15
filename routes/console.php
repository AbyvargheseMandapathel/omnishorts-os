<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('publications:process-due')->everyMinute()->withoutOverlapping();

// Keep the hosting disk bounded: drop video files that are no longer needed.
Schedule::command('videos:prune-files')->daily();

// Keep real view/like/comment/share stats fresh for published reels.
Schedule::command('analytics:refresh')->hourly();
