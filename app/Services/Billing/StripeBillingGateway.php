<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Support\BillingPrice;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeBillingGateway implements BillingGateway
{
    public function __construct(private StripeClient $stripe) {}

    public function checkoutUrl(Tenant $tenant, string $interval): string
    {
        $customerId = $this->ensureCustomer($tenant);
        $yearly = in_array($interval, ['yearly', 'year'], true)
            && $tenant->monthly_price_override_pence === null;
        $priceId = (string) config($yearly ? 'billing.yearly_price_id' : 'billing.monthly_price_id');
        $pence = $yearly
            ? BillingPrice::listYearlyPence()
            : BillingPrice::forTenant($tenant);

        $lineItem = $tenant->monthly_price_override_pence !== null || $priceId === ''
            ? [
                'price_data' => [
                    'currency' => 'gbp',
                    'unit_amount' => $pence,
                    'recurring' => ['interval' => $yearly ? 'year' : 'month'],
                    'product_data' => ['name' => config('product.name')],
                ],
                'quantity' => 1,
            ]
            : [
                'price' => $priceId,
                'quantity' => 1,
            ];

        if (($lineItem['price'] ?? '') === '' && ! isset($lineItem['price_data'])) {
            throw new RuntimeException('Stripe price ids are not configured.');
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'success_url' => route('billing.index').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.index'),
            'line_items' => [$lineItem],
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'interval' => $yearly ? 'yearly' : 'monthly',
            ],
            'subscription_data' => [
                'metadata' => ['tenant_id' => (string) $tenant->id],
            ],
        ]);

        return (string) $session->url;
    }

    public function topUpCheckoutUrl(Tenant $tenant): string
    {
        $customerId = $this->ensureCustomer($tenant);
        $pence = BillingPrice::topUpPence();
        $size = (int) config('billing.sms_topup_size');

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customerId,
            'success_url' => route('billing.index').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.index'),
            'line_items' => [[
                'price_data' => [
                    'currency' => 'gbp',
                    'unit_amount' => $pence,
                    'product_data' => ['name' => $size.' extra texts'],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
                'kind' => 'sms_topup',
            ],
        ]);

        return (string) $session->url;
    }

    private function ensureCustomer(Tenant $tenant): string
    {
        if ($tenant->stripe_customer_id) {
            return $tenant->stripe_customer_id;
        }

        $customer = $this->stripe->customers->create([
            'email' => $tenant->email,
            'name' => $tenant->name,
            'metadata' => ['tenant_id' => (string) $tenant->id],
        ]);

        $tenant->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    public function constructEvent(string $payload, string $signature): array
    {
        $secret = (string) config('billing.billing_webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        return $event->toArray();
    }

    public function invoices(Tenant $tenant): array
    {
        if (! $tenant->stripe_customer_id) {
            return [];
        }

        $list = $this->stripe->invoices->all([
            'customer' => $tenant->stripe_customer_id,
            'limit' => 24,
        ]);

        return collect($list->data)->map(function (Invoice $invoice) {
            $amount = number_format(($invoice->amount_paid ?? 0) / 100, 2);

            return [
                'id' => $invoice->id,
                'date' => $invoice->created ? date('Y-m-d', $invoice->created) : '',
                'amount' => '£'.$amount,
                'status' => $invoice->status ?? '',
                'url' => $invoice->hosted_invoice_url,
            ];
        })->all();
    }

    public function paymentMethodLabel(Tenant $tenant): ?string
    {
        if (! $tenant->stripe_customer_id) {
            return null;
        }

        $customer = $this->stripe->customers->retrieve($tenant->stripe_customer_id, [
            'expand' => ['invoice_settings.default_payment_method'],
        ]);

        $method = $customer->invoice_settings->default_payment_method ?? null;

        if (is_object($method) && isset($method->card)) {
            return ucfirst((string) $method->card->brand).' ending '.$method->card->last4;
        }

        return null;
    }

    public function nextInvoiceAt(Tenant $tenant): ?string
    {
        if (! $tenant->stripe_subscription_id) {
            return null;
        }

        $subscription = $this->stripe->subscriptions->retrieve($tenant->stripe_subscription_id);
        $end = $subscription->current_period_end ?? null;

        return $end ? date('Y-m-d', $end) : null;
    }

    public function pause(Tenant $tenant): void
    {
        if ($tenant->stripe_subscription_id) {
            $this->stripe->subscriptions->update($tenant->stripe_subscription_id, [
                'pause_collection' => ['behavior' => 'void'],
            ]);
        }

        $tenant->forceFill([
            'subscription_status' => 'paused',
            'paused_at' => now(),
        ])->save();
    }

    public function resume(Tenant $tenant): void
    {
        if ($tenant->stripe_subscription_id) {
            $this->stripe->subscriptions->update($tenant->stripe_subscription_id, [
                'pause_collection' => '',
            ]);
        }

        $tenant->forceFill([
            'subscription_status' => 'active',
            'paused_at' => null,
        ])->save();
    }

    public function cancel(Tenant $tenant): void
    {
        if ($tenant->stripe_subscription_id) {
            $this->stripe->subscriptions->cancel($tenant->stripe_subscription_id);
        }

        $tenant->forceFill([
            'subscription_status' => 'cancelled',
            'cancelled_at' => now(),
            'stripe_subscription_id' => null,
        ])->save();
    }
}
