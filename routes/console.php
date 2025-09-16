<?php
// routes/console.php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Task Scheduling
|--------------------------------------------------------------------------
|
| Here you may define all of your scheduled tasks. Laravel's scheduler
| allows you to fluently and expressively define your command schedule.
|
*/

// Mail sync: Every 30 minutes
Schedule::command('mail:sync-new')
    ->everyThirtyMinutes()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onFailure(function () {
        \Log::error('Scheduled mail sync failed');
    });

// Mailbox size check: Daily at 2 AM
Schedule::command('mail:check-sizes --alert --resolve-alerts')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->emailOutputOnFailure(config('mail.admin_email'));

// Purge operations: Weekly on Sundays at 3 AM
Schedule::command('mail:purge-old')
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->emailOutputOnFailure(config('mail.admin_email'));

// Health checks: Every 5 minutes
Schedule::command('mail:health-check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Queue monitoring: Every minute
Schedule::command('horizon:snapshot')
    ->everyMinute()
    ->withoutOverlapping();

// Clean up failed jobs: Daily at 1 AM
Schedule::command('queue:prune-failed --hours=168') // Keep failed jobs for 1 week
    ->dailyAt('01:00');

// Clean up old sync logs: Weekly on Mondays at 4 AM
Schedule::command('mail:cleanup-logs')
    ->weeklyOn(1, '04:00')
    ->withoutOverlapping();