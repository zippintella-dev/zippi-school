<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * PART M2 — generate the coming school day's trips at 02:30 school-local.
 *
 * ⚠ The timezone is explicit. A server running UTC would fire this at 08:00 IST,
 * two bells into the morning it was supposed to plan.
 *
 * withoutOverlapping: a slow run must never have a second copy start on top of
 * it. The generator is idempotent, but two concurrent runs can still interleave
 * their resequencing writes.
 *
 * Needs `php artisan schedule:work` (or a system cron calling schedule:run) to
 * actually fire. Until then, run it by hand — PART M11's JIT generation is the
 * designed safety net for a missed 02:30 and is not built yet.
 */
Schedule::command('school:generate-trips --tomorrow')
    ->dailyAt('02:30')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->onOneServer();
