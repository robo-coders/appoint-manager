<?php

namespace App\Services\Stripe;

use App\Models\Booking;
use App\Models\Tenant;

interface StripeGateway
{
    public function createExpressAccount(Tenant $tenant): string;

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string;

    /**
     * @return array{charges_enabled: bool, currently_due: list<string>}
     */
    public function retrieveAccount(string $accountId): array;

    /**
     * @return array{id: string, client_secret: string}
     */
    public function createPaymentIntent(Tenant $tenant, Booking $booking): array;

    public function refundPaymentIntent(string $paymentIntentId, string $accountId): string;

    /**
     * @return array{id: string, type: string, account: string|null, data: array<string, mixed>}
     */
    public function constructEvent(string $payload, string $signature): array;
}
