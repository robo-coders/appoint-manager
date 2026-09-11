<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ErrorPage
{
    /** @return array{ */
    public static function for(Request $request, int $code): array
    {
        $surface = Surface::current($request->getHost(), $request->path());

        return [
            'surface' => $surface,
            'code' => $code,
            'eyebrow' => self::eyebrow($code),
            'title' => self::title($surface, $code),
            'body' => self::body($surface, $code),
            'ways' => self::ways($surface, $code),
            'tone' => $code === 500 ? 'danger' : 'quiet',
        ];
    }

    private static function eyebrow(int $code): string
    {
        return $code.' '.match ($code) {
            403 => 'Forbidden',
            404 => 'Not found',
            419 => 'Session expired',
            429 => 'Too many requests',
            500 => 'Server error',
            503 => 'Unavailable',
            default => 'Error',
        };
    }

    private static function title(Surface $surface, int $code): string
    {
        return match ($code) {
            403 => 'You cannot open this',
            404 => $surface === Surface::Book
                ? 'This booking link does not go anywhere'
                : 'There is nothing at this address',
            419 => 'You were signed out while that page was open',
            429 => 'Too many tries, too quickly',
            500 => 'Something on our side broke',
            503 => config('product.name').' is down for a few minutes',
            default => 'Something went wrong on our side',
        };
    }

    private static function body(Surface $surface, int $code): string
    {
        return match ($code) {
            403 => $surface === Surface::Admin
                ? 'This account is not a super admin, or the address is not on the allowlist.'
                : 'You are signed in, but not as someone who can see this. If that is wrong, '
                    .'whoever owns the account can change what you can reach.',

            404 => match ($surface) {
                Surface::Book => 'The salon may have changed its link, or a character may have been '
                    .'dropped when it was copied. It is worth checking the message it came in — '
                    .'and if you have the salon’s number, they can send it again.',
                Surface::Admin => 'No route matches.',
                default => 'The page may have moved, or the link may have been mistyped. Nothing '
                    .'has been lost — everything below still works.',
            },

            419 => 'Signing in again takes a moment and puts you back where you were. '
                .'Nothing you had already saved is affected.',

            429 => 'This is a limit that protects the account, and it clears on its own. '
                .'Wait a minute and try once more.',

            500 => 'It has been logged and we can see it. Nothing you did caused this, and '
                .'your data is not affected.',

            503 => $surface === Surface::Book
                ? 'Bookings will be back shortly. If the appointment is today, calling the salon '
                    .'is faster than waiting for this.'
                : 'It is being updated and will be back in a few minutes. Nothing is lost — '
                    .'this is a planned pause, not a fault.',

            default => 'Try again in a moment.',
        };
    }

    /** @return list<array{label: string, href: string, note?: string}> */
    private static function ways(Surface $surface, int $code): array
    {
        if ($code === 503) {
            return [];
        }

        if ($code === 419) {
            return [[
                'label' => 'Sign in and carry on',
                'href' => ($surface === Surface::Admin ? Surface::Admin : Surface::App)->path('login'),
                'note' => 'You will land back on the page you were on.',
            ]];
        }

        return match ($surface) {
            Surface::Book => [],

            Surface::Admin => [
                ['label' => 'Tenants', 'href' => Surface::Admin->path()],
            ],

            Surface::App => [
                ['label' => 'Today’s diary', 'href' => Surface::App->path('diary')],
                ['label' => 'All bookings', 'href' => Surface::App->path('bookings')],
                ['label' => 'Customers', 'href' => Surface::App->path('customers')],
            ],

            Surface::Marketing => [
                ['label' => config('product.name'), 'href' => Surface::Marketing->path()],
                ['label' => 'Sign in', 'href' => Surface::App->path('login')],
            ],
        };
    }
}
