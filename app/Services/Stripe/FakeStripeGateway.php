<?php

namespace App\Services\Stripe;

use App\Models\Booking;
use App\Models\Tenant;
use RuntimeException;

final class FakeStripeGateway implements StripeGateway
{
    /** @var array<string, array{charges_enabled: bool, currently_due: list<string>}> */
    public array $accounts = [];

    /** @var list<array{tenant_id: int, booking_id: int, amount: int}> */
    public array $intents = [];

    /** @var list<string> */
    public array $refunds = [];

    public int $intentSeq = 0;

    public bool $throwOnCreate = false;

    /**
     * A last line of defence: this class accepts a hardcoded signature and takes no
     * money. If it is ever reachable in production, that is the emergency.
     */
    /**
     * A second lock on the same door.
     *
     * The binding in `AppServiceProvider` already makes this class unreachable
     * outside `testing`. This is here anyway because the class is `new`-able —
     * a test helper, a seeder or a future refactor can construct one directly,
     * and the thing it would then be willing to do is accept a forged webhook.
     *
     * It refuses everywhere except `testing`, not just in production. AUDIT C1
     * is specifically about an environment nobody thought of.
     */
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
        ];

        return $id;
    }

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return 'https://connect.stripe.test/setup/'.$accountId.'?return='.urlencode($returnUrl).'&refresh='.urlencode($refreshUrl);
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->accounts[$accountId] ?? [
            'charges_enabled' => false,
            'currently_due' => ['external_account'],
        ];
    }

    public function createPaymentIntent(Tenant $tenant, Booking $booking): array
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
        ];

        return [
            'id' => $id,
            'client_secret' => $id.'_secret_test',
        ];
    }

    public function refundPaymentIntent(string $paymentIntentId, string $accountId): string
    {
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
