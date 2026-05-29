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
| Intraday validate runs US weekdays 09:30-15:45 ET.
| Simulate status runs US weekdays 09:30-16:10 ET.
| Command-level guards also enforce these market windows.
*/


// Nasdaq Trader universe refresh (Sunday 17:00 America/New_York)
Schedule::command('universe:build-nasdaq')
    ->timezone('America/New_York')
    ->sundays()
    ->at('17:00')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/scheduler-nasdaq-universe.log'));

// Weekend workflow scan (Sunday 18:00 America/New_York)
Schedule::command('workflow:weekend-scan')
    ->timezone('America/New_York')
    ->sundays()
    ->at('18:00')
    ->withoutOverlapping(240)
    ->appendOutputTo(storage_path('logs/scheduler-weekend-scan.log'));


// Daily refine workflow (US trading weekdays, 05:30 America/New_York)
Schedule::command('workflow:daily-refine')
    ->timezone('America/New_York')
    ->weekdays()
    ->at('05:30')
    ->withoutOverlapping(240)
    ->appendOutputTo(storage_path('logs/scheduler-daily-refine.log'));

// Intraday validation (US weekdays, every 5 min from 09:30 to 15:45 America/New_York)
Schedule::command('prompt:intraday-validate')
    ->timezone('America/New_York')
    ->weekdays()
    ->everyFiveMinutes()
    ->between('09:30', '15:45')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-intraday-validate.log'));

// Simulated trade status tracking loop (US weekdays, every 2 min from 09:30 to 16:05 America/New_York)
Schedule::command('trades:simulate-status')
    ->timezone('America/New_York')
    ->weekdays()
    ->everyTwoMinutes()
    ->between('09:30', '16:05')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-simulate-status.log'));

// End-of-day final simulated status sync (US weekdays, 16:10 America/New_York)
Schedule::command('trades:simulate-status')
    ->timezone('America/New_York')
    ->weekdays()
    ->at('16:10')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-simulate-status.log'));

// Prompt D trade review (US weekdays, after final simulated status sync)
Schedule::command('prompt:trade-review --limit=20')
    ->timezone('America/New_York')
    ->weekdays()
    ->at('16:30')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/scheduler-trade-review.log'));
