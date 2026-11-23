<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 5: QA scores age out confirmed reports after 90 days, so recompute daily.
Schedule::command('lugha:qa-scores')->dailyAt('03:30');

// Shared hosting has no supervisor: the one cron entry (schedule:run, every minute) also drains the queue
// (order and feedback emails). A worker that finds the queue empty exits at once.
// The overlap lock expires after 5 min: if the host kills a background worker before it can release it, the queue
// would otherwise stall for 24 h. A second worker never takes a running job (queue retry_after is 1 h).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5)->runInBackground();
