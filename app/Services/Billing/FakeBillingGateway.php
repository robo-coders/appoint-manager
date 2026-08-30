<?php

namespace App\Services\Billing;

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

    public static function reset(): void
    {
        self::$events = [];
        self::$checkout = 'https://checkout.stripe.test/session';
        self::$lastCheckoutPence = null;
        self::$lastTopUpTenantId = null;
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
}
