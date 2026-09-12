<?php

namespace App\Services\Stripe;

use App\Models\Booking;
use App\Models\Tenant;
use RuntimeException;

final class FakeStripeGateway implements StripeGateway
{
    /** @var array<string, array{charges_enabled: bool, currently_due: list<string>, country?: string, default_currency?: string}> */
    public array $accounts = [];

    /** @var list<array{tenant_id: int, booking_id: int, amount: int, capture_method: string}> */
    public array $intents = [];

    /** @var list<string> */
    public array $captures = [];

    /** @var list<string> */
    public array $cancels = [];

    /** @var list<string> */
    public array $refunds = [];

    public int $intentSeq = 0;

    public bool $throwOnCreate = false;

    public bool $throwOnRefund = false;

    private function refuseOutsideTesting(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException(
                'FakeStripeGateway is for the test suite only. It accepts forged webhook signatures '
                .'and charges no cards; resolving it anywhere else is a configuration error.'
            );
        }
    }

    public function completeAccount(string $accountId): void
    {
        $this->accounts[$accountId] = [
            ...$this->accounts[$accountId] ?? [],
            'charges_enabled' => true,
            'currently_due' => [],
        ];
    }

    public function createExpressAccount(Tenant $tenant): string
    {
        $id = 'acct_fake_'.$tenant->id;
        $this->accounts[$id] = [
            'charges_enabled' => false,
            'currently_due' => ['external_account'],
            'country' => (string) $tenant->country,
            'default_currency' => strtolower((string) $tenant->currency),
        ];

        return $id;
    }

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return 'https://connect.stripe.test/setup/'.$accountId.'?return='.urlencode($returnUrl).'&refresh='.urlencode($refreshUrl);
    }

    public function retrieveAccount(string $accountId): array
    {
        $account = $this->accounts[$accountId] ?? [
            'charges_enabled' => false,
            'currently_due' => ['external_account'],
        ];

        return [
            'charges_enabled' => $account['charges_enabled'],
            'currently_due' => $account['currently_due'],
        ];
    }

    public function createPaymentIntent(Tenant $tenant, Booking $booking, string $captureMethod = 'automatic'): array
    {
        $this->refuseOutsideTesting();

        if ($this->throwOnCreate) {
            throw new RuntimeException('Stripe unavailable.');
        }

        $this->intentSeq++;
        $id = 'pi_fake_'.$this->intentSeq;
        $this->intents[] = [
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'amount' => $booking->deposit_at_booking->amount,
            'capture_method' => $captureMethod,
        ];

        return [
            'id' => $id,
            'client_secret' => $id.'_secret_test',
        ];
    }

    public function capturePaymentIntent(string $paymentIntentId, string $accountId): void
    {
        $this->refuseOutsideTesting();
        $this->captures[] = $paymentIntentId;
    }

    public function cancelPaymentIntent(string $paymentIntentId, string $accountId): void
    {
        $this->refuseOutsideTesting();
        $this->cancels[] = $paymentIntentId;
    }

    public function refundPaymentIntent(string $paymentIntentId, string $accountId): string
    {
        if ($this->throwOnRefund) {
            throw new RuntimeException('Stripe refund failed.');
        }

        $id = 're_fake_'.$paymentIntentId;
        $this->refunds[] = $paymentIntentId;

        return $id;
    }

    public function constructEvent(string $payload, string $signature): array
    {
        $this->refuseOutsideTesting();

        if ($signature !== 't=1,v1=test') {
            throw new RuntimeException('Invalid Stripe signature.', 400);
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! isset($decoded['id'], $decoded['type'])) {
            throw new RuntimeException('Invalid Stripe payload.', 400);
        }

        return [
            'id' => (string) $decoded['id'],
            'type' => (string) $decoded['type'],
            'account' => isset($decoded['account']) ? (string) $decoded['account'] : null,
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }
}
