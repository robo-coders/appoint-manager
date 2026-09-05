<?php

namespace App\BetaSandbox;

/**
 * What a sandbox owns, in one place.
 *
 * Reset and fast-forward are the same question asked twice — *which rows belong
 * to this salon's lived-in life, as opposed to its setup?* — so the answer is
 * declared once here rather than as two lists that can drift. A table added to
 * the product is added here or it is silently left out of both, which is the
 * failure mode worth designing against: a reset that leaves half a shop behind
 * is worse than one that refuses.
 *
 * Every list below is a table name plus the columns that matter. Nothing here
 * knows a tenant id; the callers add `where tenant_id = ?` to every statement,
 * without exception.
 */
final class SandboxTables
{
    /**
     * The transactional tables, in the order they can be deleted.
     *
     * Order is foreign keys inwards: a row is deleted only once nothing points
     * at it. `bookings` before `waitlist_entries` because a claimed offer's
     * booking references the entry it came from; `subjects` before `customers`
     * because a pet belongs to a person.
     *
     * `rebook_sends` is the once-per-cycle ledger behind the overdue chases.
     * Leaving it would make a reset shop refuse to chase the same subject twice
     * — the unique index would still hold the old cycle — so it goes.
     *
     * **`loyalty_packages` is deliberately not here, and `loyalty_enrolments`
     * is.** A package is configuration: it is created on Settings → Loyalty
     * beside the on/off switch, and wiping it would contradict the promise the
     * confirmation dialog makes about settings surviving. An enrolment is a
     * customer's progress, which is transactional and cascades off a customer
     * anyway. This is a deviation from the brief, recorded in BETA_SANDBOX.md.
     *
     * @return list<string>
     */
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

    /**
     * Every datetime column a fast-forward moves, by table.
     *
     * The model is "this salon's whole world slides backwards", so `created_at`
     * and `updated_at` move with the rest. Leaving them would produce a shop
     * where a booking is in the past but the row claims to have been written
     * tomorrow — and `bookings.created_at` is load-bearing, not cosmetic: it is
     * what `bookings:release-expired` measures a checkout hold against.
     *
     * `time_off` is in the list even though it is configuration rather than
     * transaction. A week of leave that stayed put while the diary moved would
     * silently land on a different set of appointments.
     *
     * The `tenants` row is deliberately absent — see `FastForward`.
     *
     * @return array<string, list<string>>
     */
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

    /**
     * Date columns — no time part, so they shift by whole days only.
     *
     * `rebook_sends.due_on` is the cycle key in that table's unique index. It is
     * a `DATE`, and subtracting seconds from it in MySQL returns a `DATETIME`
     * that then truncates unpredictably, so it is moved separately in days.
     *
     * @return array<string, list<string>>
     */
    public static function shiftableDates(): array
    {
        return [
            'rebook_sends' => ['due_on'],
        ];
    }
}
