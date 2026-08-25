<?php

/*
|--------------------------------------------------------------------------
| Machine-to-machine — no surface
|--------------------------------------------------------------------------
|
| Webhooks and health checks. These are not one of the four user surfaces:
| nobody browses them, they carry no session, and they authenticate by
| signature rather than by cookie.
|
| They are registered on every host so a provider's configured URL keeps
| working whichever hostname it was set up against. Each is CSRF-exempt and
| verifies its own signature — see bootstrap/app.php.
|
*/

use App\Http\Controllers\BillingWebhookController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TwilioStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
Route::post('/stripe/billing/webhook', BillingWebhookController::class)->name('stripe.billing.webhook');
Route::post('/twilio/status', TwilioStatusController::class)->name('twilio.status');
