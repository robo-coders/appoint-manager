<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use RuntimeException;

class FakeBillingGateway implements BillingGateway
{
    /** @var list<array<string, mixed>> */
    public static array $events = [];

    public static string $checkout = 'https://checkout.stripe.test/session';

    public static function reset(): void
    {
        self::$events = [];
        self::$checkout = 'https://checkout.stripe.test/session';
    }

    public function checkoutUrl(Tenant $tenant, string $interval): string
    {
        return self::$checkout.'?interval='.$interval.'&tenant='.$tenant->id;
    }

    public function constructEvent(string $payload, string $signature): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('FakeBillingGateway must never handle production traffic.');
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
            'amount' => $tenant->plan === 'yearly' ? '£390.00' : '£39.00',
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
