<?php

use App\Http\Controllers\ManageBookingController;
use App\Http\Controllers\PreviewBookingController;
use App\Http\Controllers\Public\IcalFeedController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\SlotOfferController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:booking-manage')->group(function (): void {
    Route::get('/b/{token}', [ManageBookingController::class, 'show'])->name('booking.manage.show');
    Route::get('/b/{token}/availability', [ManageBookingController::class, 'availability'])->name('booking.manage.availability');
    Route::post('/b/{token}/cancel', [ManageBookingController::class, 'cancel'])->name('booking.manage.cancel');
    Route::post('/b/{token}/reschedule', [ManageBookingController::class, 'reschedule'])->name('booking.manage.reschedule');
    Route::get('/offer/{token}', [SlotOfferController::class, 'show'])->name('offer.show');
    Route::post('/offer/{token}/claim', [SlotOfferController::class, 'claim'])->name('offer.claim');
});

Route::get('/preview/{token}', PreviewBookingController::class)
    ->middleware('throttle:booking-manage')
    ->name('booking.preview');

Route::get('/ical/{tenantSlug}/{token}.ics', [IcalFeedController::class, 'show'])
    ->where('tenantSlug', '[a-z0-9-]+')
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:calendar-feed')
    ->name('ical.feed');

Route::middleware('public-tenant')->group(function (): void {
    Route::get('/{tenant_slug}', [PublicBookingController::class, 'show'])->name('public.booking.show');
    Route::get('/{tenant_slug}/availability', [PublicBookingController::class, 'availability'])
        ->middleware('throttle:public-availability')
        ->name('public.booking.availability');
    Route::post('/{tenant_slug}/bookings', [PublicBookingController::class, 'store'])
        ->middleware('throttle:public-booking')
        ->name('public.booking.store');
    Route::post('/{tenant_slug}/waitlist', [PublicBookingController::class, 'waitlist'])
        ->middleware('throttle:public-booking')
        ->name('public.booking.waitlist');
});
