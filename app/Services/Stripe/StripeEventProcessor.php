<?php

namespace App\Services\Stripe;

use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Models\StripeEvent;
use App\Models\Tenant;
use App\Models\WebhookFailure;
use App\Services\Notifications\Notifier;
use App\Support\TenantContext;

final class StripeEventProcessor
{
    public function __construct(private StripeGateway $stripe, private Notifier $notifier) {}

    public function process(StripeEvent $event): void
    {
        match ($event->type) {
            'payment_intent.succeeded' => $this->paymentSucceeded($event),
            'payment_intent.payment_failed' => null,
            'charge.refunded' => $this->chargeRefunded($event),
            'account.updated' => $this->accountUpdated($event),
            default => null,
        };
    }

    /**
     * A Connect endpoint receives events from every connected account, and a
     * connected account controls the metadata on its own PaymentIntents. So the
     * booking named in metadata is a claim, not a fact: it is only believed when
     * the reporting account owns that booking and the money actually matches.
     */
    private function paymentSucceeded(StripeEvent $event): void
    {
        $object = $this->object($event);
        $bookingId = (int) ($object['metadata']['booking_id'] ?? 0);

        if ($bookingId <= 0) {
            $this->reject($event, 'payment_intent.succeeded carried no booking_id in metadata.');

            return;
        }

        $booking = Booking::withoutGlobalScopes()->whereKey($bookingId)->first();

        if ($booking === null) {
            $this->reject($event, "payment_intent.succeeded named unknown booking {$bookingId}.");

            return;
        }

        $tenant = Tenant::query()->find($booking->tenant_id);

        if ($tenant === null) {
            $this->reject($event, "Booking {$bookingId} has no tenant.");

            return;
        }

        if (! $this->accountOwns($event, $tenant)) {
            $this->reject($event, sprintf(
                'Account %s reported a payment for booking %d, which belongs to account %s.',
                $event->account_id ?? 'unknown',
                $bookingId,
                $tenant->stripe_account_id ?? 'none',
            ));

            return;
        }

        app(TenantContext::class)->set($tenant);

        if ($booking->status === BookingStatus::Confirmed) {
            return;
        }

        $expected = $booking->deposit_at_booking->amount;
        $received = (int) ($object['amount_received'] ?? $object['amount'] ?? 0);

        if ($received < $expected) {
            $this->reject($event, sprintf(
                'Booking %d expects %d but the intent received %d.',
                $bookingId,
                $expected,
                $received,
            ));

            return;
        }

        $currency = strtolower((string) ($object['currency'] ?? ''));

        if ($currency !== '' && $currency !== strtolower((string) $tenant->currency)) {
            $this->reject($event, sprintf(
                'Booking %d is in %s but the intent settled in %s.',
                $bookingId,
                $tenant->currency,
                $currency,
            ));

            return;
        }

        $booking->forceFill([
            'status' => BookingStatus::Confirmed,
            'deposit_status' => DepositStatus::Paid,
            'deposit_paid_at' => now(),
        ])->save();

        $this->notifier->bookingConfirmed($booking);
    }

    private function chargeRefunded(StripeEvent $event): void
    {
        $object = $this->object($event);
        $intentId = (string) ($object['payment_intent'] ?? '');

        if ($intentId === '') {
            return;
        }

        $booking = Booking::withoutGlobalScopes()->where('stripe_payment_intent_id', $intentId)->first();

        if ($booking === null) {
            return;
        }

        $tenant = Tenant::query()->find($booking->tenant_id);

        if ($tenant === null || ! $this->accountOwns($event, $tenant)) {
            $this->reject($event, sprintf(
                'Account %s reported a refund for intent %s, which it does not own.',
                $event->account_id ?? 'unknown',
                $intentId,
            ));

            return;
        }

        $booking->forceFill(['deposit_status' => DepositStatus::Refunded])->save();
    }

    private function accountUpdated(StripeEvent $event): void
    {
        $object = $this->object($event);
        $accountId = (string) ($object['id'] ?? '');

        if ($accountId === '') {
            return;
        }

        // An account.updated may only speak for the account that sent it.
        if ($event->account_id !== null && ! hash_equals((string) $event->account_id, $accountId)) {
            $this->reject($event, sprintf(
                'Account %s reported an update for account %s.',
                $event->account_id,
                $accountId,
            ));

            return;
        }

        $tenant = Tenant::query()->where('stripe_account_id', $accountId)->first();

        if ($tenant === null) {
            return;
        }

        $account = $this->stripe->retrieveAccount($accountId);
        $tenant->forceFill([
            'stripe_onboarding_complete' => $account['charges_enabled'],
            'stripe_requirements' => $account['currently_due'],
        ])->save();
    }

    /**
     * Direct charges always carry the connected account. An event with no account
     * is a platform event and can never speak for a tenant's booking.
     */
    private function accountOwns(StripeEvent $event, Tenant $tenant): bool
    {
        $eventAccount = (string) ($event->account_id ?? '');
        $tenantAccount = (string) ($tenant->stripe_account_id ?? '');

        if ($eventAccount === '' || $tenantAccount === '') {
            return false;
        }

        return hash_equals($tenantAccount, $eventAccount);
    }

    private function reject(StripeEvent $event, string $reason): void
    {
        WebhookFailure::query()->create([
            'source' => 'connect',
            'event_id' => $event->event_id,
            'type' => $event->type,
            'message' => $reason,
            'payload' => $event->payload,
        ]);

        report(new StripeEventRejected($reason));
    }

    /**
     * @return array<string, mixed>
     */
    private function object(StripeEvent $event): array
    {
        $payload = $event->payload ?? [];
        $object = $payload['object'] ?? $payload['data']['object'] ?? [];

        return is_array($object) ? $object : [];
    }
}
