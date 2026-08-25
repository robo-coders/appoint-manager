<?php

/*
|--------------------------------------------------------------------------
| Public booking — book.{domain}/{slug}
|--------------------------------------------------------------------------
|
| A stranger on a phone, once. No auth and no auth session; the only cookie
| this surface may set is the manage-link cookie, scoped to this host.
|
| Route order matters: the fixed prefixes must be declared before the
| `{tenant_slug}` wildcard or they are swallowed by it.
|
*/

use App\Http\Controllers\ManageBookingController;
use App\Http\Controllers\PreviewBookingController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\SlotOfferController;
use Illuminate\Support\Facades\Route;

// Manage an existing booking, and claim a waitlist offer. Both are reached
// from an emailed or texted link carrying an unguessable token.
Route::middleware('throttle:booking-manage')->group(function (): void {
    Route::get('/b/{token}', [ManageBookingController::class, 'show'])->name('booking.manage.show');
    Route::get('/b/{token}/availability', [ManageBookingController::class, 'availability'])->name('booking.manage.availability');
    Route::post('/b/{token}/cancel', [ManageBookingController::class, 'cancel'])->name('booking.manage.cancel');
    Route::post('/b/{token}/reschedule', [ManageBookingController::class, 'reschedule'])->name('booking.manage.reschedule');
    Route::get('/offer/{token}', [SlotOfferController::class, 'show'])->name('offer.show');
    Route::post('/offer/{token}/claim', [SlotOfferController::class, 'claim'])->name('offer.claim');
});

// A salon previewing its own page before going live.
Route::get('/preview/{token}', PreviewBookingController::class)
    ->middleware('throttle:booking-manage')
    ->name('booking.preview');

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
