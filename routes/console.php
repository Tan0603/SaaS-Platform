<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Overdue reminders: tenants:run initialises each tenant in turn, so the command only ever sees one tenant's data.
Schedule::command('tenants:run invoices:mark-overdue')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onOneServer();
