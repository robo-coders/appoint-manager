<?php

namespace App\Services\Billing;

use App\Exceptions\BillingPreviewException;
use App\Models\Tenant;
use App\Support\BillingPrice;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
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

    public function previewSwap(Tenant $tenant, string $interval): array
    {
        $yearly = in_array($interval, ['yearly', 'year'], true);
        $oldYearly = $tenant->plan === 'yearly';
        $oldPrice = BillingPrice::money($oldYearly ? BillingPrice::listYearlyPence() : BillingPrice::listMonthlyPence())->formatted();
        $newPrice = BillingPrice::money($yearly ? BillingPrice::listYearlyPence() : BillingPrice::listMonthlyPence())->formatted();
        $last4 = $tenant->card_last4 ?? '••••';

        try {
            $subscriptionId = (string) $tenant->stripe_subscription_id;
            $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
            $itemId = $subscription->items->data[0]->id ?? null;
            $priceId = (string) config($yearly ? 'billing.yearly_price_id' : 'billing.monthly_price_id');

            $params = [
                'customer' => $this->ensureCustomer($tenant),
                'subscription' => $subscriptionId,
                'subscription_proration_behavior' => 'create_prorations',
            ];

            if ($itemId && $priceId !== '') {
                $params['subscription_items'] = [['id' => $itemId, 'price' => $priceId]];
            }

            $invoice = $this->stripe->invoices->upcoming($params);
            $chargePence = (int) ($invoice->amount_due ?? 0);
            $creditPence = 0;

            foreach ($invoice->lines->data ?? [] as $line) {
                $amount = (int) ($line->amount ?? 0);
                if ($amount < 0) {
                    $creditPence += abs($amount);
                }
            }

            $end = $tenant->current_period_end ?? now()->addMonth();
            $days = max(0, (int) now()->startOfDay()->diffInDays($end->copy()->startOfDay()));
        } catch (ApiErrorException|RuntimeException) {
            throw BillingPreviewException::unavailable();
        }

        $charge = BillingPrice::money(max($chargePence, 0))->formatted();
        $credit = BillingPrice::money($creditPence)->formatted();

        return [
            'old_price' => $oldPrice.' / '.($oldYearly ? 'yr' : 'mo'),
            'new_price' => $newPrice.' / '.($yearly ? 'yr' : 'mo'),
            'statement' => 'You have '.$days.' days left on the '.($oldYearly ? 'yearly' : 'monthly')
                .' period, so '.$credit.' is credited and '.$charge.' is charged to •••• '.$last4.' today.',
            'charge' => $charge,
            'charge_pence' => max($chargePence, 0),
        ];
    }

    public function swap(Tenant $tenant, string $interval): void
    {
        $yearly = in_array($interval, ['yearly', 'year'], true);
        $subscriptionId = (string) $tenant->stripe_subscription_id;
        $subscription = $this->stripe->subscriptions->retrieve($subscriptionId);
        $itemId = $subscription->items->data[0]->id ?? null;
        $priceId = (string) config($yearly ? 'billing.yearly_price_id' : 'billing.monthly_price_id');

        $payload = [
            'proration_behavior' => 'create_prorations',
        ];

        if ($itemId && $priceId !== '') {
            $payload['items'] = [['id' => $itemId, 'price' => $priceId]];
        } else {
            $payload['items'] = [[
                'id' => $itemId,
                'price_data' => [
                    'currency' => 'gbp',
                    'unit_amount' => $yearly ? BillingPrice::listYearlyPence() : BillingPrice::forTenant($tenant),
                    'recurring' => ['interval' => $yearly ? 'year' : 'month'],
                    'product_data' => ['name' => config('product.name')],
                ],
            ]];
        }

        $updated = $this->stripe->subscriptions->update($subscriptionId, $payload);
        app(SubscriptionState::class)->apply($tenant, $updated->toArray(), $yearly ? 'yearly' : 'monthly');
    }

    public function cancelAtPeriodEnd(Tenant $tenant): void
    {
        if ($tenant->stripe_subscription_id) {
            $updated = $this->stripe->subscriptions->update($tenant->stripe_subscription_id, [
                'cancel_at_period_end' => true,
            ]);
            app(SubscriptionState::class)->apply($tenant, $updated->toArray(), $tenant->plan);

            return;
        }

        $tenant->forceFill([
            'cancel_at_period_end' => true,
            'subscription_ends_at' => $tenant->current_period_end ?? now()->addMonth(),
        ])->save();
    }

    public function resumeCancellation(Tenant $tenant): void
    {
        if ($tenant->stripe_subscription_id) {
            $updated = $this->stripe->subscriptions->update($tenant->stripe_subscription_id, [
                'cancel_at_period_end' => false,
            ]);
            app(SubscriptionState::class)->apply($tenant, $updated->toArray(), $tenant->plan);

            return;
        }

        $tenant->forceFill([
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'subscription_ends_at' => null,
        ])->save();
    }

    public function createSetupIntent(Tenant $tenant): string
    {
        $intent = $this->stripe->setupIntents->create([
            'customer' => $this->ensureCustomer($tenant),
            'payment_method_types' => ['card'],
        ]);

        return (string) $intent->client_secret;
    }

    public function confirmPaymentMethod(Tenant $tenant, string $paymentMethodId): void
    {
        $customerId = $this->ensureCustomer($tenant);

        $this->stripe->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
        $this->stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        $method = $this->stripe->paymentMethods->retrieve($paymentMethodId);
        $card = $method->card ?? null;

        $tenant->forceFill([
            'card_brand' => $card->brand ?? $tenant->card_brand,
            'card_last4' => $card->last4 ?? $tenant->card_last4,
            'card_exp_month' => $card->exp_month ?? $tenant->card_exp_month,
            'card_exp_year' => $card->exp_year ?? $tenant->card_exp_year,
        ])->save();
    }

    public function refresh(Tenant $tenant): void
    {
        if ($tenant->stripe_subscription_id) {
            $subscription = $this->stripe->subscriptions->retrieve($tenant->stripe_subscription_id);
            app(SubscriptionState::class)->apply($tenant, $subscription->toArray(), $tenant->plan);
        }

        if (! $tenant->stripe_customer_id) {
            return;
        }

        $customer = $this->stripe->customers->retrieve($tenant->stripe_customer_id, [
            'expand' => ['invoice_settings.default_payment_method'],
        ]);
        $method = $customer->invoice_settings->default_payment_method ?? null;

        if (is_object($method) && isset($method->card)) {
            $tenant->forceFill([
                'card_brand' => $method->card->brand ?? null,
                'card_last4' => $method->card->last4 ?? null,
                'card_exp_month' => $method->card->exp_month ?? null,
                'card_exp_year' => $method->card->exp_year ?? null,
            ])->save();
        }
    }

    public function paymentFailureCode(string $id): ?string
    {
        try {
            if (str_starts_with($id, 'pi_')) {
                $intent = $this->stripe->paymentIntents->retrieve($id, [
                    'expand' => ['latest_charge'],
                ]);
                $fromIntent = $intent->last_payment_error->decline_code
                    ?? $intent->last_payment_error->code
                    ?? null;

                if (is_string($fromIntent) && $fromIntent !== '') {
                    return $fromIntent;
                }

                $charge = $intent->latest_charge ?? null;

                if (is_string($charge) && $charge !== '') {
                    return $this->paymentFailureCode($charge);
                }

                if (is_object($charge)) {
                    $code = $charge->failure_code ?? $charge->outcome->reason ?? null;

                    return is_string($code) && $code !== '' ? $code : null;
                }

                return null;
            }

            $charge = $this->stripe->charges->retrieve($id);
            $code = $charge->failure_code ?? $charge->outcome->reason ?? null;

            return is_string($code) && $code !== '' ? $code : null;
        } catch (ApiErrorException) {
            return null;
        }
    }
}
