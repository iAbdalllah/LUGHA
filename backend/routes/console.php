<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 5: QA scores age out confirmed reports after 90 days, so recompute daily.
Schedule::command('lugha:qa-scores')->dailyAt('03:30');
