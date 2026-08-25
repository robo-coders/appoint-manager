<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:release-expired')->everyMinute();
Schedule::command('waitlist:expire-offers')->everyMinute();
Schedule::command('bookings:daily-agenda')->hourly();
Schedule::command('billing:dunning')->daily();
Schedule::command('db:backup --disk=s3')->daily()->at('02:15');
