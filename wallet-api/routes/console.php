<?php

use App\Jobs\Cashback\StartWeeklyCashback;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every Monday at 03:00 pay cashback for LAST week (Monday..Sunday).
// The week is computed inside the callback, when the task runs: `schedule:work` loads this file once and
// stays up for weeks, so a value computed out here would keep paying for the week it started in.
// onOneServer: with several servers running the scheduler, only one of them starts the job (needs a shared cache lock, e.g. Redis).
// A block closure, not `fn () =>`: dispatch() queues the job when its PendingDispatch is destroyed,
// and an arrow function would return it to the scheduler, which keeps it alive.
Schedule::call(function () {
    StartWeeklyCashback::dispatch(now()->subWeek()->startOfWeek()->toDateString());
})
    ->name('weekly-cashback')
    ->weeklyOn(1, '03:00')
    ->onOneServer();
