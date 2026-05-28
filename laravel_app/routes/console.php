<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Lean Swing Assistant Scheduler (T14.3)
|--------------------------------------------------------------------------
| Note: Scheduler only orchestrates existing simulated/offline workflows.
| It does not alter EXECUTION_DRIVER and does not enable live trading.
*/

// Weekend workflow scan (Sunday 20:00 America/New_York)
Schedule::command('workflow:weekend-scan')
    ->sundays()
    ->at('20:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-weekend-scan.log'));


// IBKR universe refresh (Friday 14:00 America/New_York)
Schedule::command('universe:build-ibkr')
    ->fridays()
    ->at('14:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-universe-build.log'));

// Daily refine workflow (US trading weekdays, 08:30 America/New_York)
Schedule::command('workflow:daily-refine')
    ->weekdays()
    ->at('08:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-daily-refine.log'));

// Intraday validation (US weekdays, every 5 min from 09:30 to 15:45 America/New_York)
Schedule::command('prompt:intraday-validate')
    ->weekdays()
    ->everyFiveMinutes()
    ->between('09:30', '15:45')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-intraday-validate.log'));

// Simulated trade status tracking loop (US weekdays, every 2 min from 09:30 to 16:05 America/New_York)
Schedule::command('trades:simulate-status')
    ->weekdays()
    ->everyTwoMinutes()
    ->between('09:30', '16:05')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-simulate-status.log'));

// End-of-day final simulated status sync (US weekdays, 16:10 America/New_York)
Schedule::command('trades:simulate-status')
    ->weekdays()
    ->at('16:10')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-simulate-status.log'));
