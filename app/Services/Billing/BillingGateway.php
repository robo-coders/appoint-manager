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
}
