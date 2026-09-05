<?php

/*
|--------------------------------------------------------------------------
| The operator app — app.{domain}
|--------------------------------------------------------------------------
|
| The salon owner. Auth, tenant context, onboarding gate and the billing
| read-only gate. Super admin lives on its own host and nothing of it is
| reachable from here.
|
| Auth routes live here rather than in a shared file: the admin surface has
| its own login so that a super admin never authenticates on this host.
|
*/

use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\CalendarSettingsController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPrivacyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiaryController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OverdueController;
use App\Http\Controllers\PaymentSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffCalendarController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TimeOffController;
use App\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

/*
 * Handoff from the admin surface. A super admin cannot be issued a cookie for
 * this host from admin.{domain}, so impersonation arrives as a short-lived
 * signed URL that is exchanged here for a normal app session.
 */
Route::get('/impersonate/{user}', [ImpersonationController::class, 'start'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('impersonation.start');

Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonation.stop');

/*
 * A staff member's calendar feed.
 *
 * Outside `auth` on purpose, and outside `tenant` because it has to be: a
 * calendar client fetches a URL on a timer with no cookie, so there is no
 * session to resolve a tenant from. The token is the credential and the row it
 * finds establishes the tenant context — see `StaffCalendarController`.
 *
 * Throttled harder than the surface default. A calendar client polls every
 * fifteen minutes; sixty requests an hour per token is generous for that and is
 * a ceiling on anybody trying tokens.
 */
Route::get('/calendar/{token}.ics', StaffCalendarController::class)
    ->where('token', '[0-9a-f]{32}')
    ->middleware('throttle:60,1')
    ->name('calendar.feed');

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::patch('/onboarding/business', [OnboardingController::class, 'updateBusiness'])->name('onboarding.business');
    Route::patch('/onboarding/services', [OnboardingController::class, 'updateServices'])->name('onboarding.services');
    Route::patch('/onboarding/staff', [OnboardingController::class, 'updateStaff'])->name('onboarding.staff');
    Route::patch('/onboarding/hours', [OnboardingController::class, 'updateHours'])->name('onboarding.hours');
});

Route::middleware(['auth', 'tenant', 'onboarding', 'subscribed'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/diary', DiaryController::class)->name('diary.index');
    Route::get('/search', SearchController::class)->name('search');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->name('bookings.destroy');
    Route::post('/bookings/{booking}/approve', [BookingController::class, 'approve'])->name('bookings.approve');
    Route::post('/bookings/{booking}/decline', [BookingController::class, 'decline'])->name('bookings.decline');
    /*
     * Marking an appointment as having happened. `BookingStatus::Completed` was
     * read in four places and written by nothing but the demo seeders before
     * this existed — see `BookingService::complete`.
     */
    Route::post('/bookings/{booking}/complete', [BookingController::class, 'complete'])->name('bookings.complete');
    /*
     * And marking one as missed. `BookingStatus::NoShow` was read by the
     * dashboard's no-show rate and written by nothing, so the stat could only
     * ever be zero — see `BookingService::markNoShow`.
     */
    Route::post('/bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.no-show');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/customers/{customer}/export', [CustomerPrivacyController::class, 'export'])->name('customers.export');
    Route::delete('/customers/{customer}', [CustomerPrivacyController::class, 'destroy'])->name('customers.destroy');

    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('/billing/top-up', [BillingController::class, 'topUp'])->name('billing.top-up');
    Route::post('/billing/pause', [BillingController::class, 'pause'])->name('billing.pause');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');

    Route::get('/imports', [ImportController::class, 'show'])->name('imports.show');
    Route::post('/imports/customers', [ImportController::class, 'customers'])->name('imports.customers');
    Route::post('/imports/bookings', [ImportController::class, 'bookings'])->name('imports.bookings');

    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    Route::patch('/services/reorder', [ServiceController::class, 'reorder'])->name('services.reorder');
    Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::patch('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::patch('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');

    Route::get('/availability', [AvailabilityController::class, 'index'])->name('availability.index');
    Route::put('/availability/{staff}', [AvailabilityController::class, 'sync'])->name('availability.sync');

    Route::get('/time-off', [TimeOffController::class, 'index'])->name('time-off.index');
    Route::post('/time-off', [TimeOffController::class, 'store'])->name('time-off.store');
    Route::delete('/time-off/{time_off}', [TimeOffController::class, 'destroy'])->name('time-off.destroy');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/settings/booking-link/qr', [SettingsController::class, 'qr'])->name('settings.booking-link.qr');
    Route::get('/settings/branding', [BrandingController::class, 'edit'])->name('settings.branding.edit');
    Route::patch('/settings/branding', [BrandingController::class, 'update'])->name('settings.branding.update');
    Route::get('/settings/calendar', [CalendarSettingsController::class, 'show'])->name('settings.calendar.show');
    Route::post('/settings/calendar/{staff}/regenerate', [CalendarSettingsController::class, 'regenerate'])->name('settings.calendar.regenerate');
    Route::get('/settings/loyalty', [LoyaltyController::class, 'edit'])->name('settings.loyalty.edit');
    Route::patch('/settings/loyalty', [LoyaltyController::class, 'update'])->name('settings.loyalty.update');
    Route::get('/settings/payments', [PaymentSettingsController::class, 'show'])->name('settings.payments.show');
    Route::post('/settings/payments/connect', [PaymentSettingsController::class, 'connect'])->name('settings.payments.connect');
    Route::get('/settings/payments/refresh', [PaymentSettingsController::class, 'refresh'])->name('settings.payments.refresh');
    Route::get('/settings/payments/return', [PaymentSettingsController::class, 'returned'])->name('settings.payments.return');

    Route::get('/waitlist', [WaitlistController::class, 'index'])->name('waitlist.index');
    Route::post('/waitlist', [WaitlistController::class, 'store'])->name('waitlist.store');

    Route::get('/overdue', [OverdueController::class, 'index'])->name('overdue.index');
    Route::post('/overdue/preview-enable', [OverdueController::class, 'previewEnable'])->name('overdue.preview-enable');
    Route::post('/overdue/enable', [OverdueController::class, 'enable'])->name('overdue.enable');
    Route::post('/overdue/disable', [OverdueController::class, 'disable'])->name('overdue.disable');
    Route::post('/overdue/{subject}/contacted', [OverdueController::class, 'contacted'])->name('overdue.contacted');
    Route::post('/overdue/{subject}/snooze', [OverdueController::class, 'snooze'])->name('overdue.snooze');
    Route::post('/overdue/{subject}/stop', [OverdueController::class, 'stop'])->name('overdue.stop');
    Route::post('/overdue/{subject}/resume', [OverdueController::class, 'resume'])->name('overdue.resume');

    /*
     * Beta sandbox — sample data, fast-forward, reset. See BETA_SANDBOX.md.
     * Everything it owns is in that one file; deleting the feature is deleting
     * this line and routes/beta-sandbox.php.
     */
    require __DIR__.'/beta-sandbox.php';

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
