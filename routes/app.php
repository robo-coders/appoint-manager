<?php

use App\Http\Controllers\AppearanceController;
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
use App\Http\Controllers\Settings\BillingController as SettingsBillingController;
use App\Http\Controllers\Settings\CalendarSyncController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffCalendarController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TimeOffController;
use App\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::get('/impersonate/{user}', [ImpersonationController::class, 'start'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('impersonation.start');

Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonation.stop');

Route::get('/calendar/{token}.ics', StaffCalendarController::class)
    ->where('token', '[0-9a-f]{32}')
    ->middleware('throttle:60,1')
    ->name('calendar.feed');

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');

    Route::get('/onboarding/slug-available', [OnboardingController::class, 'checkSlug'])
        ->middleware('throttle:60,1')
        ->name('onboarding.slug');

    Route::patch('/onboarding/basics', [OnboardingController::class, 'updateBasics'])->name('onboarding.basics');
    Route::patch('/onboarding/business', [OnboardingController::class, 'updateBusiness'])->name('onboarding.business');
    Route::patch('/onboarding/services', [OnboardingController::class, 'updateServices'])->name('onboarding.services');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    Route::patch('/appearance', [AppearanceController::class, 'update'])->name('appearance.update');
});

Route::middleware(['auth', 'tenant', 'onboarding', 'subscribed', 'billing-access'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/diary', DiaryController::class)->name('diary.index');
    Route::get('/search', SearchController::class)->name('search');

    Route::get('/bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('/bookings/export', [BookingController::class, 'export'])->name('bookings.export');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
    Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->name('bookings.destroy');
    Route::post('/bookings/{booking}/approve', [BookingController::class, 'approve'])->name('bookings.approve');
    Route::post('/bookings/{booking}/decline', [BookingController::class, 'decline'])->name('bookings.decline');
    Route::post('/bookings/{booking}/complete', [BookingController::class, 'complete'])->name('bookings.complete');
    Route::post('/bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.no-show');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::patch('/customers/{customer}/notes', [CustomerController::class, 'updateNotes'])->name('customers.notes.update');
    Route::post('/customers/{customer}/require-full-payment', [CustomerController::class, 'requireFullPayment'])->name('customers.require-full-payment');
    Route::post('/customers/{customer}/dismiss-rule', [CustomerController::class, 'dismissSuggestedRule'])->name('customers.dismiss-rule');
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
    Route::get('/settings/calendar-sync', [CalendarSyncController::class, 'index'])->name('settings.calendar-sync');
    Route::post('/settings/calendar-sync/regenerate', [CalendarSyncController::class, 'regenerate'])->name('settings.calendar-sync.regenerate');
    Route::post('/settings/calendar-sync/content-mode', [CalendarSyncController::class, 'updateContentMode'])->name('settings.calendar-sync.content-mode');
    Route::get('/settings/loyalty', [LoyaltyController::class, 'edit'])->name('settings.loyalty.edit');
    Route::patch('/settings/loyalty', [LoyaltyController::class, 'update'])->name('settings.loyalty.update');
    Route::get('/settings/payments', [PaymentSettingsController::class, 'show'])->name('settings.payments.show');
    Route::post('/settings/payments/connect', [PaymentSettingsController::class, 'connect'])->name('settings.payments.connect');
    Route::get('/settings/payments/refresh', [PaymentSettingsController::class, 'refresh'])->name('settings.payments.refresh');
    Route::get('/settings/payments/return', [PaymentSettingsController::class, 'returned'])->name('settings.payments.return');

    Route::get('/settings/billing', [SettingsBillingController::class, 'show'])->name('settings.billing');
    Route::post('/settings/billing/preview', [SettingsBillingController::class, 'preview'])->name('settings.billing.preview');
    Route::post('/settings/billing/swap', [SettingsBillingController::class, 'swap'])->name('settings.billing.swap');
    Route::post('/settings/billing/cancel', [SettingsBillingController::class, 'cancel'])->name('settings.billing.cancel');
    Route::post('/settings/billing/resume', [SettingsBillingController::class, 'resume'])->name('settings.billing.resume');
    Route::post('/settings/billing/refresh', [SettingsBillingController::class, 'refresh'])->name('settings.billing.refresh');
    Route::post('/settings/billing/setup-intent', [SettingsBillingController::class, 'setupIntent'])->name('settings.billing.setup-intent');
    Route::post('/settings/billing/payment-method', [SettingsBillingController::class, 'paymentMethod'])->name('settings.billing.payment-method');
    Route::post('/settings/billing/checkout', [SettingsBillingController::class, 'checkout'])->name('settings.billing.checkout');
    Route::get('/settings/billing/receipts/{billing_receipt}/download', [SettingsBillingController::class, 'download'])->name('settings.billing.receipts.download');
    Route::get('/settings/billing/invoices.csv', [SettingsBillingController::class, 'export'])->name('settings.billing.export');

    Route::get('/waitlist', [WaitlistController::class, 'index'])->name('waitlist.index');
    Route::post('/waitlist', [WaitlistController::class, 'store'])->name('waitlist.store');
    Route::post('/waitlist/{booking}/offer', [WaitlistController::class, 'offer'])->name('waitlist.offer');

    Route::get('/overdue', [OverdueController::class, 'index'])->name('overdue.index');
    Route::post('/overdue/preview-enable', [OverdueController::class, 'previewEnable'])->name('overdue.preview-enable');
    Route::post('/overdue/enable', [OverdueController::class, 'enable'])->name('overdue.enable');
    Route::post('/overdue/disable', [OverdueController::class, 'disable'])->name('overdue.disable');
    Route::post('/overdue/{subject}/contacted', [OverdueController::class, 'contacted'])->name('overdue.contacted');
    Route::post('/overdue/{subject}/snooze', [OverdueController::class, 'snooze'])->name('overdue.snooze');
    Route::post('/overdue/{subject}/stop', [OverdueController::class, 'stop'])->name('overdue.stop');
    Route::post('/overdue/{subject}/resume', [OverdueController::class, 'resume'])->name('overdue.resume');

    require __DIR__.'/beta-sandbox.php';
    require __DIR__.'/sandbox.php';

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
