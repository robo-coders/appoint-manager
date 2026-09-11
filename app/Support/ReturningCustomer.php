<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class ReturningCustomer
{
    public const COOKIE = 'am_booking_ref';

    private const LIFETIME_MINUTES = 60 * 24 * 365;

    public static function forRequest(Request $request, Tenant $tenant): ?Customer
    {
        $token = self::token($request);

        if ($token === null) {
            return null;
        }

        $booking = Booking::withoutGlobalScopes()
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('public_token', $token)
            ->where('tenant_id', $tenant->id)
            ->first();

        return $booking?->customer;
    }

    public static function token(Request $request): ?string
    {
        foreach ([$request->query('ref'), $request->cookie(self::COOKIE)] as $candidate) {
            if (is_string($candidate) && self::looksLikeToken($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

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

    private static function looksLikeToken(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
