<?php

namespace App\Services\Billing;

use App\Models\Tenant;

interface BillingGateway
{
    /**
     * @return string Checkout URL
     */
    public function checkoutUrl(Tenant $tenant, string $interval): string;

    /**
     * @return array<string, mixed>
     */
    public function constructEvent(string $payload, string $signature): array;

    /**
     * @return list<array{id: string, date: string, amount: string, status: string, url: string|null}>
     */
    public function invoices(Tenant $tenant): array;

    public function paymentMethodLabel(Tenant $tenant): ?string;

    public function nextInvoiceAt(Tenant $tenant): ?string;

    public function pause(Tenant $tenant): void;

    public function resume(Tenant $tenant): void;

    public function cancel(Tenant $tenant): void;

    /**
     * One-off Checkout for an SMS top-up. Applied on `checkout.session.completed`.
     */
    public function topUpCheckoutUrl(Tenant $tenant): string;

    /**
     * @return array{old_price: string, new_price: string, statement: string, charge: string, charge_pence: int}
     */
    public function previewSwap(Tenant $tenant, string $interval): array;

    public function swap(Tenant $tenant, string $interval): void;

    public function cancelAtPeriodEnd(Tenant $tenant): void;

    public function resumeCancellation(Tenant $tenant): void;

    public function createSetupIntent(Tenant $tenant): string;

    public function confirmPaymentMethod(Tenant $tenant, string $paymentMethodId): void;

    public function refresh(Tenant $tenant): void;

    public function paymentFailureCode(string $id): ?string;
}
