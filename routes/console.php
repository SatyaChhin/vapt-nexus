<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Picks up new and finished Nessus scans; finished runs get their report.
Schedule::command('nessus:sync')->everyMinute()->withoutOverlapping(10);

// Imports record the trend themselves; this keeps a point for every day.
Schedule::command('findings:snapshot')->dailyAt('23:55');
