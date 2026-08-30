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
/*
 * Hourly, not daily. The send window is evaluated in each tenant's own
 * timezone, and a single 09:00 run is inside exactly one timezone's window —
 * a salon in Sydney would never be sent for at all. Hourly is safe because the
 * once-per-cycle rule is a unique index in `rebook_sends`, not a condition in
 * the job. See `App\Services\Rebooking\RebookAttempts`.
 */
Schedule::command('rebooking:send')->hourlyAt(5)->withoutOverlapping();
Schedule::command('db:backup --disk=s3')->daily()->at('02:15');
