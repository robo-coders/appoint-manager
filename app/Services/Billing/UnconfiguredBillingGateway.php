<?php

namespace App\Services\Billing;

use App\Exceptions\PaymentsNotConfiguredException;
use App\Models\Tenant;

/**
 * Local-only stand-in so the billing *screen* can be looked at without keys.
 *
 * It does not take money, does not invent invoices, and it is not the fake
 * used by the test suite — that one accepts forged webhook signatures. Checkout
 * and top-up still refuse; they need Stripe, and the keys are empty on this
 * machine on purpose.
 */
class UnconfiguredBillingGateway implements BillingGateway
{
    public function checkoutUrl(Tenant $tenant, string $interval): string
    {
        throw $this->missing();
    }

    public function topUpCheckoutUrl(Tenant $tenant): string
    {
        throw $this->missing();
    }

    public function constructEvent(string $payload, string $signature): array
    {
        throw $this->missing();
    }

    public function invoices(Tenant $tenant): array
    {
        return [];
    }

    public function paymentMethodLabel(Tenant $tenant): ?string
    {
        return null;
    }

    public function nextInvoiceAt(Tenant $tenant): ?string
    {
        return null;
    }

    public function pause(Tenant $tenant): void
    {
        throw $this->missing();
    }

    public function resume(Tenant $tenant): void
    {
        throw $this->missing();
    }

    public function cancel(Tenant $tenant): void
    {
        throw $this->missing();
    }

    private function missing(): PaymentsNotConfiguredException
    {
        return PaymentsNotConfiguredException::missing(
            'STRIPE_SECRET',
            'The billing screen works without it; taking a card does not.',
        );
    }
}
