<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Who the booking page thinks it is talking to.
 *
 * Recognition happens two ways, and only two:
 *
 *   - **The manage-link cookie.** Set when somebody opens `/b/{token}` — the
 *     link in their own confirmation email — and read on their next visit to
 *     the salon's booking page.
 *   - **`?ref={token}` on a reminder link.** The same token, arriving in the URL
 *     instead of a cookie, which is how a text message recognises somebody who
 *     books from a different browser. It also *sets* the cookie, so the next
 *     visit needs no link.
 *
 * Never by email address. Guessing at identity from a typed-in address is how a
 * booking page cheerfully tells a stranger the name of somebody else's dog.
 *
 * ## Why a raw token in a cookie is acceptable here
 *
 * The token is a capability: whoever holds it can cancel or move that booking.
 * It is *already* a capability the customer holds — it is the link in their
 * email — so putting it in a `HttpOnly`, `Secure`, `SameSite=Lax` cookie pinned
 * to the booking host adds no exposure that the email did not already have. It
 * is not readable by JavaScript, it is not sent cross-site, and it is scoped to
 * one hostname.
 *
 * What it must not do is leak *across tenants*. The booking host serves every
 * salon from one origin, so a cookie set at salon A is presented to salon B.
 * `forRequest()` therefore checks the booking's `tenant_id` against the tenant
 * being served and returns null on a mismatch — a customer of one salon is a
 * stranger at the next.
 */
final class ReturningCustomer
{
    public const COOKIE = 'am_booking_ref';

    /** A year. Longer than any sensible grooming interval, and it is only a hint. */
    private const LIFETIME_MINUTES = 60 * 24 * 365;

    /**
     * The customer this visitor is, or null.
     *
     * Returns the customer even when the referenced booking belongs to a
     * different service or staff member than they want this time — that is the
     * suggester's problem, not this one's. All this decides is identity.
     */
    public static function forRequest(Request $request, Tenant $tenant): ?Customer
    {
        $token = self::token($request);

        if ($token === null) {
            return null;
        }

        $booking = Booking::withoutGlobalScopes()
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('public_token', $token)
            // The check that stops one salon's cookie identifying a visitor at
            // another salon on the same hostname.
            ->where('tenant_id', $tenant->id)
            ->first();

        return $booking?->customer;
    }

    /** The token this request carries, from the URL first and the cookie second. */
    public static function token(Request $request): ?string
    {
        foreach ([$request->query('ref'), $request->cookie(self::COOKIE)] as $candidate) {
            if (is_string($candidate) && self::looksLikeToken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The cookie to attach to a response, so the next visit needs no link.
     *
     * Host-only: no leading dot, so it is never presented to a sibling
     * subdomain. `Secure` is conditional on the request being HTTPS purely so
     * local development over plain HTTP still works; production is HTTPS.
     */
    public static function remember(string $token, bool $secure): Cookie
    {
        return cookie()->make(
            name: self::COOKIE,
            value: $token,
            minutes: self::LIFETIME_MINUTES,
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    public static function forget(): Cookie
    {
        return cookie()->forget(self::COOKIE);
    }

    /**
     * A UUID, which is what `bookings.public_token` is.
     *
     * Cheap, but it means a malformed cookie never reaches the database as a
     * query, and it keeps the shape of the value an explicit decision rather
     * than whatever a client happens to send.
     */
    private static function looksLikeToken(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
