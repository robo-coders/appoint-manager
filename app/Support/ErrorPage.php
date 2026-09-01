<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What an error page says, and where it lets you go.
 *
 * **The person who hit the error is not one person.** An operator mid-shift on
 * `app.` has the whole product behind them and wants the diary back. A customer
 * on `book.` followed a link a salon gave them, has never heard of Appoint
 * Manager, and must not be shown a login or told about "your diary". A stranger
 * on the marketing host wants the marketing site. And on `admin.` it is us, at
 * 2am, who want the shortest possible sentence.
 *
 * One page with a "Go home" button would be wrong for three of those four:
 * *home* is a different place for each, and for the customer it is a SaaS
 * marketing site they have no use for.
 *
 * Every string is built here rather than in the template, per the standing
 * rule, and because the same status means a different thing on each surface —
 * a 404 on `book.` is a mistyped salon link and a 404 on `app.` is a bookmark
 * that has gone stale.
 *
 * **Nothing in this class queries anything.** 503 renders with the database
 * down; a page that is only correct while the database is up is not an error
 * page.
 */
final class ErrorPage
{
    /**
     * @return array{
     *     surface: Surface,
     *     code: int,
     *     eyebrow: string,
     *     title: string,
     *     body: string,
     *     ways: list<array{label: string, href: string, note?: string}>,
     *     tone: 'quiet'|'danger',
     * }
     */
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
            /*
             * Only a genuine fault is `danger`. A 503 is a planned pause — we
             * chose it, it is going to end, and nothing is broken — so painting
             * it in `--danger` tells the reader the opposite of the sentence
             * underneath it. DESIGN.md rations that colour to reporting a
             * problem, and a deploy is not one.
             */
            'tone' => $code === 500 ? 'danger' : 'quiet',
        ];
    }

    /**
     * The status, and its name, in mono.
     *
     * The number is metadata rather than the headline. A 200px "404" is the
     * least useful thing on the page for the person reading it — they know
     * something is wrong, they are trying to find out *what* — and it is
     * decoration with a size. It stays, small, because it is the one thing
     * worth quoting to support.
     */
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
                // A customer, on a phone, holding a link a salon gave them.
                // They have never heard of us and there is nothing on our
                // marketing site they want.
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
                // The heading already says the product's name. Repeating it in
                // the first three words of the sentence under it reads like two
                // paragraphs stitched together, because that is what it was.
                : 'It is being updated and will be back in a few minutes. Nothing is lost — '
                    .'this is a planned pause, not a fault.',

            default => 'Try again in a moment.',
        };
    }

    /**
     * The ways out, in the order this particular person would want them.
     *
     * A list of real destinations rather than one "Go home" button. Home is a
     * different place on every surface, and on `book.` it is a place the person
     * reading has no use for — so `book.` gets no link to us at all. A dead end
     * that is honest about being one beats a button that wastes a tap.
     *
     * @return list<array{label: string, href: string, note?: string}>
     */
    private static function ways(Surface $surface, int $code): array
    {
        // Nothing to click on a page that exists because the app is not
        // running. Every link would 503 as well.
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
            /*
             * A customer gets no link to us. Our marketing site sells
             * appointment software to salon owners; they are not one, and a tap
             * through to our own home page helps nobody.
             */
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
