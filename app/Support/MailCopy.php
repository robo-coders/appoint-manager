<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Tenant;

final class MailCopy
{
    public static function when(Booking $booking, Tenant $tenant): string
    {
        return $booking->starts_at->timezone($tenant->timezone)->format('l j F, H:i');
    }

    public static function time(Booking $booking, Tenant $tenant): string
    {
        return $booking->starts_at->timezone($tenant->timezone)->format('H:i');
    }

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

    /** @return list<array{label: string, value: string, mono?: bool}> */
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

    /** @param  list<array{label: string, value: string, mono?: bool}>  $rows */
    public static function asText(array $rows): string
    {
        $width = max(array_map(fn (array $row) => mb_strlen($row['label']), $rows)) + 2;

        return implode("\n", array_map(
            fn (array $row) => str_pad($row['label'], $width).$row['value'],
            $rows,
        ));
    }
}
