<?php

use App\Http\Controllers\BillingWebhookController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TwilioInboundController;
use App\Http\Controllers\TwilioStatusController;
use App\Http\Middleware\VerifyTwilioSignature;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
Route::post('/stripe/billing/webhook', BillingWebhookController::class)->name('stripe.billing.webhook');

Route::middleware(VerifyTwilioSignature::class)->group(function (): void {
    Route::post('/twilio/status', TwilioStatusController::class)->name('twilio.status');
    Route::post('/twilio/inbound', TwilioInboundController::class)->name('twilio.inbound');
});
