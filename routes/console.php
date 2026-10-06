<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lms:send-reminders')
    ->dailyAt(config('lms.reminder_time'))
    ->timezone(config('lms.reminder_timezone'))
    ->withoutOverlapping(30);
