<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\PaymentFailure;
use App\Models\Tenant;
use App\Notifications\DunningFailedPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

class BillingEventProcessor
{
    public function process(BillingEvent $event): void
    {
        match ($event->type) {
            'checkout.session.completed' => $this->checkoutCompleted($event),
            'invoice.payment_succeeded' => $this->paymentSucceeded($event),
            'invoice.payment_failed' => $this->paymentFailed($event),
            'customer.subscription.updated' => $this->subscriptionUpdated($event),
            'customer.subscription.deleted' => $this->subscriptionDeleted($event),
            default => null,
        };

        $event->forceFill(['processed_at' => now()])->save();
    }

    private function checkoutCompleted(BillingEvent $event): void
    {
        $object = $this->object($event);
        $tenant = $this->tenantFrom($object);

        if ($tenant === null) {
            return;
        }

        if (($object['metadata']['kind'] ?? '') === 'sms_topup') {
            app(SmsAllowance::class)->applyTopUp($tenant);

            return;
        }

        $interval = (string) ($object['metadata']['interval'] ?? 'monthly');
        $tenant->forceFill([
            'stripe_customer_id' => $object['customer'] ?? $tenant->stripe_customer_id,
            'stripe_subscription_id' => $object['subscription'] ?? $tenant->stripe_subscription_id,
            'subscription_status' => 'active',
            'plan' => $interval === 'yearly' ? 'yearly' : 'monthly',
            'current_period_end' => $interval === 'yearly' ? now()->addYear() : now()->addMonth(),
            'subscription_ends_at' => null,
            'cancel_at_period_end' => false,
            'dunning_started_at' => null,
            'dunning_emails_sent' => 0,
            'paused_at' => null,
            'cancelled_at' => null,
        ])->save();

        app(SmsAllowance::class)->resetCycle($tenant);
    }

    private function paymentSucceeded(BillingEvent $event): void
    {
        $object = $this->object($event);
        $tenant = $this->tenantFrom($object) ?? $this->tenantByCustomer((string) ($object['customer'] ?? ''));

        if ($tenant === null) {
            return;
        }

        $periodEnd = $this->periodEndFromInvoice($object);

        $tenant->forceFill([
            'subscription_status' => 'active',
            'dunning_started_at' => null,
            'dunning_emails_sent' => 0,
            'current_period_end' => $periodEnd ?? $tenant->current_period_end,
        ])->save();

        $this->resolveFailures($tenant, $object);

        app(ReceiptRecorder::class)->record($tenant, $object, 'paid');

        $reason = (string) ($object['billing_reason'] ?? '');
        $isSubscription = ($object['subscription'] ?? null) !== null
            || str_starts_with($reason, 'subscription');

        if ($isSubscription) {
            app(SmsAllowance::class)->resetCycle($tenant);
        }
    }

    private function paymentFailed(BillingEvent $event): void
    {
        $object = $this->object($event);
        $tenant = $this->tenantFrom($object) ?? $this->tenantByCustomer((string) ($object['customer'] ?? ''));

        if ($tenant === null) {
            return;
        }

        $started = $tenant->dunning_started_at ?? now();
        $retry = isset($object['next_payment_attempt']) && $object['next_payment_attempt']
            ? CarbonImmutable::createFromTimestamp((int) $object['next_payment_attempt'])
            : null;

        $tenant->forceFill([
            'subscription_status' => 'past_due',
            'dunning_started_at' => $started,
        ])->save();

        PaymentFailure::query()->updateOrCreate(
            ['stripe_invoice_id' => (string) ($object['id'] ?? '')],
            [
                'tenant_id' => $tenant->id,
                'failure_reason' => app(DeclineReason::class)->fromInvoice($object, app(BillingGateway::class)),
                'declined_at' => isset($object['created'])
                    ? CarbonImmutable::createFromTimestamp((int) $object['created'])
                    : now(),
                'retry_at' => $retry,
                'resolved_at' => null,
            ],
        );

        app(ReceiptRecorder::class)->record($tenant, $object, 'declined');

        if ((int) $tenant->dunning_emails_sent === 0) {
            $this->notifyOwners($tenant);
            $tenant->forceFill(['dunning_emails_sent' => 1])->save();
        }
    }

    private function subscriptionUpdated(BillingEvent $event): void
    {
        $object = $this->object($event);
        $tenant = $this->tenantFrom($object)
            ?? Tenant::query()->where('stripe_subscription_id', $object['id'] ?? '')->first()
            ?? $this->tenantByCustomer((string) ($object['customer'] ?? ''));

        if ($tenant === null) {
            return;
        }

        app(SubscriptionState::class)->apply($tenant, $object);
    }

    private function subscriptionDeleted(BillingEvent $event): void
    {
        $object = $this->object($event);
        $tenant = $this->tenantFrom($object) ?? Tenant::query()
            ->where('stripe_subscription_id', $object['id'] ?? '')
            ->first();

        if ($tenant === null) {
            return;
        }

        $tenant->forceFill([
            'subscription_status' => 'cancelled',
            'cancelled_at' => now(),
            'subscription_ends_at' => $tenant->subscription_ends_at ?? now(),
            'cancel_at_period_end' => false,
            'stripe_subscription_id' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function resolveFailures(Tenant $tenant, array $invoice): void
    {
        $invoiceId = (string) ($invoice['id'] ?? '');

        if ($invoiceId === '') {
            return;
        }

        PaymentFailure::query()
            ->where('tenant_id', $tenant->id)
            ->where('stripe_invoice_id', $invoiceId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function periodEndFromInvoice(array $invoice): ?CarbonImmutable
    {
        $end = data_get($invoice, 'lines.data.0.period.end')
            ?? data_get($invoice, 'period_end');

        return $end ? CarbonImmutable::createFromTimestamp((int) $end) : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function tenantFrom(array $object): ?Tenant
    {
        $tenantId = (int) data_get($object, 'metadata.tenant_id', 0);

        if ($tenantId > 0) {
            return Tenant::query()->find($tenantId);
        }

        return null;
    }

    private function tenantByCustomer(string $customerId): ?Tenant
    {
        if ($customerId === '') {
            return null;
        }

        return Tenant::query()->where('stripe_customer_id', $customerId)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function object(BillingEvent $event): array
    {
        $payload = $event->payload ?? [];
        $object = $payload['object'] ?? $payload['data']['object'] ?? $payload;

        return is_array($object) ? $object : [];
    }

    private function notifyOwners(Tenant $tenant): void
    {
        $owners = $tenant->users()->where('role', 'owner')->get();

        if ($owners->isEmpty()) {
            return;
        }

        Notification::send($owners, new DunningFailedPayment($tenant, 0));
    }
}
