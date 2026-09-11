<?php

namespace App\BetaSandbox;

final class SandboxTables
{
    /** @return list<string> */
    public static function transactional(): array
    {
        return [
            'slot_offers',
            'rebook_sends',
            'messages',
            'loyalty_enrolments',
            'bookings',
            'waitlist_entries',
            'subjects',
            'customers',
        ];
    }

    /** @return array<string, list<string>> */
    public static function shiftable(): array
    {
        return [
            'bookings' => [
                'starts_at', 'ends_at', 'cancelled_at', 'deposit_paid_at',
                'reminder_cancelled_at', 'request_expires_at', 'created_at', 'updated_at',
            ],
            'waitlist_entries' => ['expires_at', 'created_at', 'updated_at'],
            'slot_offers' => ['starts_at', 'ends_at', 'expires_at', 'created_at', 'updated_at'],
            'subjects' => [
                'rebook_snoozed_until', 'rebook_stopped_at', 'rebook_contacted_at',
                'rebook_send_blocked_at', 'created_at', 'updated_at',
            ],
            'customers' => ['sms_opted_out_at', 'created_at', 'updated_at'],
            'messages' => ['created_at', 'updated_at'],
            'loyalty_enrolments' => ['created_at', 'updated_at'],
            'rebook_sends' => ['sent_at', 'created_at', 'updated_at'],
            'time_off' => ['starts_at', 'ends_at', 'created_at', 'updated_at'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function shiftableDates(): array
    {
        return [
            'rebook_sends' => ['due_on'],
        ];
    }
}
