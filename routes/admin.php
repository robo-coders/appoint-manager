<?php

/*
|--------------------------------------------------------------------------
| Super admin — admin.{domain}
|--------------------------------------------------------------------------
|
| Us, at 2am. Nothing here is reachable from the app surface, so a salon
| owner cannot hit one of these routes even to be told 403.
|
| Its own session cookie, its own login, and an IP allowlist when
| ADMIN_IP_ALLOWLIST is set.
|
*/

use App\Http\Controllers\Admin\AdminSessionController;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use App\Http\Controllers\SuperAdmin\VerticalController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AdminSessionController::class, 'create'])->name('admin.login');
    Route::post('/login', [AdminSessionController::class, 'store'])
        ->middleware('throttle:admin-login')
        ->name('admin.login.store');
});

Route::middleware(['auth', 'super-admin'])->group(function (): void {
    Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('admin.logout');

    Route::get('/', [SuperAdminController::class, 'index'])->name('super-admin.index');
    Route::get('/messages', [SuperAdminController::class, 'messages'])->name('super-admin.messages');
    Route::get('/failures', [SuperAdminController::class, 'failures'])->name('super-admin.failures');
    Route::get('/verticals', [VerticalController::class, 'index'])->name('super-admin.verticals');
    Route::post('/verticals', [VerticalController::class, 'store'])->name('super-admin.verticals.store');
    Route::patch('/verticals/{vertical}', [VerticalController::class, 'update'])->name('super-admin.verticals.update');
    Route::delete('/verticals/{vertical}', [VerticalController::class, 'destroy'])->name('super-admin.verticals.destroy');
    Route::post('/tenants/{tenant}/impersonate', [SuperAdminController::class, 'impersonate'])->name('super-admin.impersonate');
    Route::post('/tenants/{tenant}/extend-trial', [SuperAdminController::class, 'extendTrial'])->name('super-admin.extend-trial');
    Route::post('/tenants/{tenant}/trial', [SuperAdminController::class, 'setTrial'])->name('super-admin.trial');
    Route::post('/tenants/{tenant}/sms/allowance', [SuperAdminController::class, 'setAllowance'])->name('super-admin.sms.allowance');
    Route::post('/tenants/{tenant}/sms/ceiling', [SuperAdminController::class, 'setCeiling'])->name('super-admin.sms.ceiling');
    Route::post('/tenants/{tenant}/sms/kill', [SuperAdminController::class, 'killSms'])->name('super-admin.sms.kill');
    Route::post('/tenants/{tenant}/sms/resume', [SuperAdminController::class, 'resumeSms'])->name('super-admin.sms.resume');
    Route::post('/tenants/{tenant}/sms/grant', [SuperAdminController::class, 'grantSms'])->name('super-admin.sms.grant');
    Route::post('/tenants/{tenant}/price', [SuperAdminController::class, 'setPrice'])->name('super-admin.price');
    Route::post('/tenants/{tenant}/comp', [SuperAdminController::class, 'comp'])->name('super-admin.comp');
    Route::post('/tenants/{tenant}/flags', [SuperAdminController::class, 'flags'])->name('super-admin.flags');
    Route::post('/tenants/{tenant}/go-live', [SuperAdminController::class, 'goLive'])->name('super-admin.go-live');
    Route::post('/tenants/{tenant}/preview', [SuperAdminController::class, 'previewLink'])->name('super-admin.preview');
    Route::post('/clone-setup', [SuperAdminController::class, 'cloneSetup'])->name('super-admin.clone');
});
