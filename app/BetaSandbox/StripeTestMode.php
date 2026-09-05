<?php

namespace App\BetaSandbox;

use App\Exceptions\PaymentsNotConfiguredException;
use App\Models\Tenant;

/**
 * A beta tenant can never reach Stripe live mode.
 *
 * This is the whole of section 3 of the beta brief, and it is a hard guard
 * rather than a preference: a salon in the beta programme is testing with
 * invented customers, and a real card charge against a real connected account
 * is the one mistake in this feature that cannot be undone with a reset button.
 *
 * The rules, in order:
 *
 *   1. Not a beta tenant — the platform's configured secret, unchanged. Nothing
 *      about this class is reachable for the other 100% of tenants beyond one
 *      boolean read.
 *   2. Beta tenant, `STRIPE_TEST_SECRET` set — that key, always, whatever the
 *      global configuration says.
 *   3. Beta tenant, no test key, but the platform is *already* in test mode
 *      (`sk_test_…`) — that key. A local or staging box with only test keys is
 *      the common case and should not need a second copy of the same value.
 *   4. Beta tenant, no test key, platform in live mode — **refuse**. Falling
 *      back to the live key here is the exact failure this exists to prevent,
 *      and a payment that does not happen is recoverable in a way that a
 *      payment that does is not. `PaymentsNotConfiguredException` is the
 *      product's existing "payments cannot be reached" signal, and the surfaces
 *      that take deposits already turn it into an honest 503 rather than a
 *      stack trace.
 *
 * Restricted keys (`rk_test_…`) count as test keys; anything that is neither
 * prefix is treated as unknown and refused for a beta tenant, because a key we
 * cannot classify is a key we cannot promise is not live.
 *
 * **Where this is called from.** `StripeConnectGateway::client()` — one place,
 * because that is the single point at which every Stripe call in the product
 * resolves its credentials. See BETA_SANDBOX.md, "integration points".
 */
final class StripeTestMode
{
    /** Stripe's own prefixes for a key that cannot move real money. */
    private const TEST_PREFIXES = ['sk_test_', 'rk_test_'];

    /**
     * The secret key a call on behalf of this tenant must use.
     *
     * `$tenant` is null for the platform's own calls — constructing a webhook
     * event, for instance — which are not on any tenant's behalf and are never
     * redirected.
     */
    public static function secretFor(?Tenant $tenant): string
    {
        $configured = (string) config('services.stripe.secret');

        if (! BetaSandbox::enabled($tenant)) {
            return $configured;
        }

        $test = (string) config('services.stripe.test_secret');

        if ($test !== '' && self::isTestKey($test)) {
            return $test;
        }

        if (self::isTestKey($configured)) {
            return $configured;
        }

        throw PaymentsNotConfiguredException::missing(
            'STRIPE_TEST_SECRET',
            'Refusing to talk to Stripe for a beta tenant with a live key. Beta salons are '
            .'sandboxes: they may only ever reach Stripe test mode.'
        );
    }

    /**
     * The tenant behind a connected account id.
     *
     * `capturePaymentIntent`, `cancelPaymentIntent` and `refundPaymentIntent`
     * are handed an account id rather than a tenant, so this is how those three
     * find out whether they are acting for a beta salon. One indexed lookup on
     * a column that is already unique in practice, on three calls that happen
     * at most once per booking — the alternative is widening the gateway
     * interface for every caller in the product to serve a flag most of them
     * will never set.
     *
     * `withoutGlobalScopes()` is not needed: `tenants` is the root of the
     * tenancy tree and carries no `TenantScope`.
     */
    public static function secretForAccount(?string $accountId): string
    {
        if ($accountId === null || $accountId === '') {
            return self::secretFor(null);
        }

        return self::secretFor(
            Tenant::query()->where('stripe_account_id', $accountId)->first(),
        );
    }

    private static function isTestKey(string $key): bool
    {
        foreach (self::TEST_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
