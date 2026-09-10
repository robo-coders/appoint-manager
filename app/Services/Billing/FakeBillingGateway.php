<?php

namespace App\Services\Billing;

use App\Exceptions\BillingPreviewException;
use App\Models\Tenant;
use App\Support\BillingPrice;
use RuntimeException;

class FakeBillingGateway implements BillingGateway
{
    /** @var list<array<string, mixed>> */
    public static array $events = [];

    public static string $checkout = 'https://checkout.stripe.test/session';

    public static ?int $lastCheckoutPence = null;

    public static ?int $lastTopUpTenantId = null;

    public static bool $previewFails = false;

    /** @var array{old_price: string, new_price: string, statement: string, charge: string, charge_pence: int}|null */
    public static ?array $preview = null;

    public static string $setupSecret = 'seti_test_secret';

    public static int $refreshCalls = 0;

    /** @var array<string, string> */
    public static array $failureCodes = [];

    public static function reset(): void
    {
        self::$events = [];
        self::$checkout = 'https://checkout.stripe.test/session';
        self::$lastCheckoutPence = null;
        self::$lastTopUpTenantId = null;
        self::$previewFails = false;
        self::$preview = null;
        self::$setupSecret = 'seti_test_secret';
        self::$refreshCalls = 0;
        self::$failureCodes = [];
    }

    public function checkoutUrl(Tenant $tenant, string $interval): string
    {
        self::$lastCheckoutPence = BillingPrice::forTenant($tenant);

        return self::$checkout.'?interval=monthly&tenant='.$tenant->id.'&pence='.self::$lastCheckoutPence;
    }

    public function topUpCheckoutUrl(Tenant $tenant): string
    {
        self::$lastTopUpTenantId = $tenant->id;

        return self::$checkout.'?kind=sms_topup&tenant='.$tenant->id;
    }

    public function constructEvent(string $payload, string $signature): array
    {
        // AUDIT C1, same reasoning as FakeStripeGateway: `testing` only, never
        // "not production". A signature this accepts is a signature anyone can send.
        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'FakeBillingGateway is for the test suite only. It accepts forged webhook signatures; '
                .'resolving it anywhere else is a configuration error.'
            );
        }

        if ($signature !== 'test_billing') {
            throw new RuntimeException('Invalid billing signature.');
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! isset($decoded['id'], $decoded['type'])) {
            throw new RuntimeException('Invalid billing payload.');
        }

        return $decoded;
    }

    public function invoices(Tenant $tenant): array
    {
        if ($tenant->subscription_status !== 'active') {
            return [];
        }

        return [[
            'id' => 'in_test',
            'date' => now()->toDateString(),
            'amount' => BillingPrice::money(BillingPrice::forTenant($tenant))->formatted(),
            'status' => 'paid',
            'url' => null,
        ]];
    }

    public function paymentMethodLabel(Tenant $tenant): ?string
    {
        return $tenant->stripe_customer_id ? 'Visa ending 4242' : null;
    }

    public function nextInvoiceAt(Tenant $tenant): ?string
    {
        if ($tenant->subscription_status !== 'active') {
            return null;
        }

        return now()->addMonth()->toDateString();
    }

    public function pause(Tenant $tenant): void
    {
        $tenant->forceFill([
            'subscription_status' => 'paused',
            'paused_at' => now(),
        ])->save();
    }

    public function resume(Tenant $tenant): void
    {
        $tenant->forceFill([
            'subscription_status' => 'active',
            'paused_at' => null,
        ])->save();
    }

    public function cancel(Tenant $tenant): void
    {
        $tenant->forceFill([
            'subscription_status' => 'cancelled',
            'cancelled_at' => now(),
            'stripe_subscription_id' => null,
        ])->save();
    }

    public function previewSwap(Tenant $tenant, string $interval): array
    {
        if (self::$previewFails) {
            throw BillingPreviewException::unavailable();
        }

        if (self::$preview !== null) {
            return self::$preview;
        }

        $yearly = in_array($interval, ['yearly', 'year'], true);
        $old = BillingPrice::money($yearly ? BillingPrice::listMonthlyPence() : BillingPrice::listYearlyPence())->formatted();
        $new = BillingPrice::money($yearly ? BillingPrice::listYearlyPence() : BillingPrice::listMonthlyPence())->formatted();
        $chargePence = $yearly ? BillingPrice::listYearlyPence() : BillingPrice::listMonthlyPence();
        $last4 = $tenant->card_last4 ?? '4242';

        return [
            'old_price' => $old.' / '.($yearly ? 'mo' : 'yr'),
            'new_price' => $new.' / '.($yearly ? 'yr' : 'mo'),
            'statement' => 'You have 22 days left on the '.($yearly ? 'monthly' : 'yearly').' period, so £21.27 is credited and '
                .BillingPrice::money(max($chargePence - 2127, 0))->formatted()
                .' is charged to •••• '.$last4.' today.',
            'charge' => BillingPrice::money(max($chargePence - 2127, 0))->formatted(),
            'charge_pence' => max($chargePence - 2127, 0),
        ];
    }

    public function swap(Tenant $tenant, string $interval): void
    {
        $yearly = in_array($interval, ['yearly', 'year'], true);

        $tenant->forceFill([
            'plan' => $yearly ? 'yearly' : 'monthly',
            'subscription_status' => 'active',
            'current_period_end' => $yearly ? now()->addYear() : now()->addMonth(),
        ])->save();
    }

    public function cancelAtPeriodEnd(Tenant $tenant): void
    {
        $tenant->forceFill([
            'cancel_at_period_end' => true,
            'subscription_ends_at' => $tenant->current_period_end ?? now()->addMonth(),
        ])->save();
    }

    public function resumeCancellation(Tenant $tenant): void
    {
        $tenant->forceFill([
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
            'subscription_ends_at' => null,
            'subscription_status' => $tenant->subscription_status === 'cancelled' ? 'active' : $tenant->subscription_status,
        ])->save();
    }

    public function createSetupIntent(Tenant $tenant): string
    {
        return self::$setupSecret;
    }

    public function confirmPaymentMethod(Tenant $tenant, string $paymentMethodId): void
    {
        $tenant->forceFill([
            'card_brand' => 'visa',
            'card_last4' => '4242',
            'card_exp_month' => 4,
            'card_exp_year' => 2028,
        ])->save();
    }

    public function refresh(Tenant $tenant): void
    {
        self::$refreshCalls++;

        if ($tenant->stripe_customer_id && ! $tenant->card_last4) {
            $tenant->forceFill([
                'card_brand' => 'visa',
                'card_last4' => '4242',
                'card_exp_month' => 4,
                'card_exp_year' => 2028,
            ])->save();
        }
    }

    public function paymentFailureCode(string $id): ?string
    {
        $code = self::$failureCodes[$id] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }
}
