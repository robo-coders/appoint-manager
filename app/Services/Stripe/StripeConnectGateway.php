<?php

namespace App\Services\Stripe;

use App\BetaSandbox\StripeTestMode;
use App\Models\Booking;
use App\Models\Tenant;
use RuntimeException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeConnectGateway implements StripeGateway
{
    public function createExpressAccount(Tenant $tenant): string
    {
        $account = $this->client($tenant)->accounts->create([
            'type' => 'express',
            'country' => $tenant->country,
            'email' => $tenant->email,
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => [
                'tenant_id' => (string) $tenant->id,
            ],
        ]);

        return $account->id;
    }

    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->clientForAccount($accountId)->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ]);

        return $link->url;
    }

    public function retrieveAccount(string $accountId): array
    {
        $account = $this->clientForAccount($accountId)->accounts->retrieve($accountId, []);
        $due = [];

        foreach ($account->requirements->currently_due ?? [] as $item) {
            $due[] = (string) $item;
        }

        return [
            'charges_enabled' => (bool) $account->charges_enabled,
            'currently_due' => $due,
        ];
    }

    public function createPaymentIntent(Tenant $tenant, Booking $booking, string $captureMethod = 'automatic'): array
    {
        $params = [
            'amount' => $booking->deposit_at_booking->amount,
            'currency' => strtolower($tenant->currency),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'tenant_id' => (string) $tenant->id,
            ],
        ];

        if ($captureMethod === 'manual') {
            $params['capture_method'] = 'manual';
        }

        $fee = intdiv($booking->deposit_at_booking->amount * $tenant->platform_fee_bps, 10000);

        if ($fee > 0) {
            $params['application_fee_amount'] = $fee;
        }

        $intent = $this->client($tenant)->paymentIntents->create($params, [
            'stripe_account' => $tenant->stripe_account_id,
        ]);

        return [
            'id' => $intent->id,
            'client_secret' => (string) $intent->client_secret,
        ];
    }

    public function capturePaymentIntent(string $paymentIntentId, string $accountId): void
    {
        $this->clientForAccount($accountId)->paymentIntents->capture($paymentIntentId, [], [
            'stripe_account' => $accountId,
        ]);
    }

    public function cancelPaymentIntent(string $paymentIntentId, string $accountId): void
    {
        $this->clientForAccount($accountId)->paymentIntents->cancel($paymentIntentId, [], [
            'stripe_account' => $accountId,
        ]);
    }

    public function refundPaymentIntent(string $paymentIntentId, string $accountId): string
    {
        $refund = $this->clientForAccount($accountId)->refunds->create([
            'payment_intent' => $paymentIntentId,
        ], [
            'stripe_account' => $accountId,
        ]);

        return $refund->id;
    }

    public function constructEvent(string $payload, string $signature): array
    {
        $secret = (string) config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $exception) {
            throw new RuntimeException('Invalid Stripe signature.', 400, $exception);
        }

        return [
            'id' => $event->id,
            'type' => $event->type,
            // Present on Connect events; the account that actually reported this.
            'account' => isset($event->account) ? (string) $event->account : null,
            'data' => $event->data->toArray(),
        ];
    }

    /**
     * The Stripe client for a call made on behalf of `$tenant`.
     *
     * **BetaSandbox integration point — the one this feature has in the payment
     * path.** `StripeTestMode::secretFor()` returns the platform's configured
     * secret for every ordinary tenant, and for a beta tenant returns a test-mode
     * key or refuses outright. It is here, and only here, because this method is
     * the single place in the product where a Stripe credential is chosen: a
     * guard in a controller could be routed around by the next caller, and one
     * per public method would be seven copies of the same rule.
     *
     * See `App\BetaSandbox\StripeTestMode` and BETA_SANDBOX.md. Removing the
     * beta sandbox means putting `config('services.stripe.secret')` back here
     * and deleting the two arguments this method and `clientForAccount()` take.
     */
    private function client(?Tenant $tenant = null): StripeClient
    {
        return new StripeClient(StripeTestMode::secretFor($tenant));
    }

    /**
     * The same decision, for the three calls that are handed a connected
     * account id rather than a tenant. See `StripeTestMode::secretForAccount`.
     */
    private function clientForAccount(?string $accountId): StripeClient
    {
        return new StripeClient(StripeTestMode::secretForAccount($accountId));
    }
}
