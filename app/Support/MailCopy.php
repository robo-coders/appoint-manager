<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Tenant;

/**
 * The words in a transactional email, and the facts under them.
 *
 * Here rather than in the Blade files because they are customer-facing strings
 * and this product builds those in PHP — and because the same booking has to be
 * described twice, once in HTML and once in plain text, and two templates
 * writing the same sentence is two sentences that will eventually disagree.
 *
 * The plaintext part is not an afterthought. Somebody reads it: a phone on a
 * bad signal, a client set to text-only, a screen reader that prefers it, and
 * every spam filter that scores a message with no text alternative. It is built
 * from these same values.
 */
final class MailCopy
{
    /** When an appointment is, in the salon's timezone, spelled out. */
    public static function when(Booking $booking, Tenant $tenant): string
    {
        return $booking->starts_at->timezone($tenant->timezone)->format('l j F, H:i');
    }

    /** Just the time, for a list where the day is already the heading. */
    public static function time(Booking $booking, Tenant $tenant): string
    {
        return $booking->starts_at->timezone($tenant->timezone)->format('H:i');
    }

    /** The salon's address on one line, or null when it has not given one. */
    public static function address(Tenant $tenant): ?string
    {
        $parts = array_filter([
            $tenant->address_line_1,
            $tenant->address_line_2,
            $tenant->city,
            $tenant->postcode,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The rows of facts a confirmation shows.
     *
     * Deposit is only a row when there is one. A line reading "Deposit £0.00"
     * invites the question it exists to answer.
     *
     * @return list<array{label: string, value: string, mono?: bool}>
     */
    public static function bookingRows(Booking $booking, Tenant $tenant): array
    {
        $rows = [
            ['label' => 'What', 'value' => $booking->service->name, 'mono' => false],
            ['label' => 'When', 'value' => self::when($booking, $tenant)],
        ];

        if ($booking->staff) {
            $rows[] = ['label' => 'With', 'value' => $booking->staff->name, 'mono' => false];
        }

        if ($address = self::address($tenant)) {
            $rows[] = ['label' => 'Where', 'value' => $address, 'mono' => false];
        }

        $rows[] = ['label' => 'Price', 'value' => $booking->price_at_booking->formatted()];

        $deposit = $booking->deposit_at_booking;

        if ($deposit && $deposit->amount > 0) {
            $rows[] = ['label' => 'Deposit paid', 'value' => $deposit->formatted()];

            /*
             * `Money` has no arithmetic — it is a readonly value object with a
             * formatter and nothing else — so the remainder is built from pence
             * and wrapped again rather than by adding a `minus()` this is the
             * only caller of. `max(0, …)` because the constructor refuses a
             * negative amount, and a deposit larger than the price is a data
             * problem that must not become a 500 inside a confirmation email.
             */
            $rows[] = [
                'label' => 'Due on the day',
                'value' => (new Money(
                    max(0, $booking->price_at_booking->amount - $deposit->amount),
                    $booking->price_at_booking->currency,
                ))->formatted(),
            ];
        }

        return $rows;
    }

    /**
     * The same rows, as plain text.
     *
     * @param  list<array{label: string, value: string, mono?: bool}>  $rows
     *
     * Aligned on the label column so the values line up in a monospaced mail
     * client, which is the only place plain text is read.
     */
    public static function asText(array $rows): string
    {
        $width = max(array_map(fn (array $row) => mb_strlen($row['label']), $rows)) + 2;

        return implode("\n", array_map(
            fn (array $row) => str_pad($row['label'], $width).$row['value'],
            $rows,
        ));
    }
}
