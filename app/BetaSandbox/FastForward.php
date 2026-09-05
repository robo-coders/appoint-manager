<?php

namespace App\BetaSandbox;

use App\Enums\BookingStatus;
use App\Enums\MessageType;
use App\Models\Booking;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Booking\BookingService;
use App\Services\Notifications\Notifier;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * "Skip 1 day" / "Skip 1 week" — move one salon's world, and let the
 * consequences actually happen.
 *
 * ---------------------------------------------------------------------------
 * The design decision, and why it is both halves and not either one
 * ---------------------------------------------------------------------------
 *
 * The brief offered two shapes and asked for whichever is genuinely correct
 * end to end. Neither one is, on its own:
 *
 *   - **Shifting timestamps alone is cosmetic where it matters most.** A
 *     reminder is not a row anybody polls; it is a *delayed queue job*, put on
 *     the queue with `->delay($when)` when the booking was confirmed
 *     (`Notifier::scheduleReminder`). Moving `starts_at` backwards does not
 *     move that job, so the appointment slides to tomorrow and the reminder
 *     still fires next week. The owner presses "Skip 1 week", sees the diary
 *     move, and concludes reminders are broken.
 *   - **Running the jobs early alone changes nothing.** `bookings:release-expired`
 *     asks whether a hold is older than fifteen minutes; the hold created two
 *     seconds ago is not, however early you run it. No-show eligibility opens
 *     when an appointment is in the past, and a Friday booking is not in the
 *     past because a command ran. Every one of these automations is a question
 *     about *time*, so with the data standing still the answer stays no.
 *
 * So this does both, in that order. First the salon's own rows slide backwards
 * by the interval, which is the honest model of "a day passed": what was three
 * days out is two, what was pending fifteen minutes is now a day old, and what
 * happened this morning is yesterday. Then the automations that would have run
 * during that day are run for this tenant, immediately, through the product's
 * real services — so an expired hold is released by `BookingService::cancel`,
 * an expired request is declined by `BookingService::decline`, an unclaimed
 * slot offer is retired and re-offered by `WaitlistOfferer`, and a reminder
 * that has come due is sent by `Notifier::reminder`. None of it is
 * reimplemented here. What is here is only *which rows*, and *this tenant*.
 *
 * ---------------------------------------------------------------------------
 * Scope
 * ---------------------------------------------------------------------------
 *
 * Every statement in the shift carries `where tenant_id = ?`. Every automation
 * is handed either a tenant-filtered collection or, in the waitlist's case, the
 * tenant id itself. Nothing in this file can see another salon's rows, and the
 * test suite tries: `FastForwardTest` sits a second tenant next to the first
 * with identical data and asserts that not one of its timestamps moves.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not moved
 * ---------------------------------------------------------------------------
 *
 * The `tenants` row itself — `trial_ends_at`, `sms_cycle_started_at`. Burning
 * a trial day per press is superficially attractive and is a trap: four presses
 * of "Skip 1 week" would expire the trial, `EnsureSubscriptionWrite` would put
 * the shop into read-only, and the sandbox panel's own buttons — which are
 * POSTs — would stop working. A tester would have locked themselves out of the
 * tool they were testing, with no way back that does not involve us. Billing
 * dates stay where they are; the super admin console can already move a trial
 * on purpose.
 *
 * Delayed jobs already on the queue are likewise not rewritten. A reminder job
 * queued for next Tuesday stays queued for next Tuesday, and `SendBookingReminder`
 * declines to send twice for a booking that is no longer `Confirmed` — but a
 * booking that is still confirmed can be reminded about a second time when the
 * real clock catches up. In a sandbox full of invented people whose messages are
 * muted, that is a duplicate row in a send log. It is recorded in BETA_SANDBOX.md
 * rather than papered over.
 */
final class FastForward
{
    /** How far a press moves the world. Minutes, because the shift is in seconds. */
    public const INTERVALS = [
        'day' => 1440,
        'week' => 10080,
    ];

    public function __construct(
        private BookingService $bookings,
        private WaitlistOfferer $waitlist,
        private Notifier $notifier,
    ) {}

    /**
     * @param  'day'|'week'  $interval
     * @return array{shifted: int, released: int, declined: int, offers: int, reminders: int}
     */
    public function run(Tenant $tenant, string $interval): array
    {
        BetaSandbox::guard($tenant);

        $minutes = self::INTERVALS[$interval] ?? null;

        abort_if($minutes === null, 422);

        $context = app(TenantContext::class);
        $previous = $context->tenant();
        $context->set($tenant);

        try {
            return SandboxMute::while(function () use ($tenant, $minutes): array {
                $shifted = DB::transaction(fn (): int => $this->shift($tenant, $minutes));

                /*
                 * Outside the transaction on purpose. The automations below are
                 * the product's own services and each one manages its own
                 * consistency — `BookingService::cancel` takes a lock, refunds a
                 * deposit and offers the freed hour to the waitlist. Wrapping
                 * all of that in one long-held outer transaction would hold
                 * row locks across every booking in the shop for the duration,
                 * and a failure halfway would roll back a Stripe refund that had
                 * already happened. The shift is the part that must be atomic,
                 * and it is.
                 */
                return [
                    'shifted' => $shifted,
                    'released' => $this->releaseExpiredHolds($tenant),
                    'declined' => $this->declineExpiredRequests($tenant),
                    'offers' => $this->expireSlotOffers($tenant),
                    'reminders' => $this->sendDueReminders($tenant),
                ];
            });
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    /**
     * Slide every one of this salon's datetimes backwards.
     *
     * Raw SQL because it is one statement per table rather than one per row: a
     * shop with four hundred bookings should not become four hundred model
     * loads and saves, and no model event should fire — a booking whose
     * `starts_at` moved has not been rescheduled, and must not tell anybody it
     * has.
     *
     * `$seconds` is derived from `INTERVALS` above and is an integer this class
     * computed, never a value off the request; the controller maps the button
     * to a key and this method rejects anything that is not one.
     */
    private function shift(Tenant $tenant, int $minutes): int
    {
        $seconds = $minutes * 60;
        $days = intdiv($minutes, 1440);
        $rows = 0;

        foreach (SandboxTables::shiftable() as $table => $columns) {
            $updates = [];

            foreach ($columns as $column) {
                // NULL stays NULL: MySQL's DATE_SUB returns NULL for a NULL
                // argument, so an unpaid deposit does not acquire a date.
                $updates[$column] = DB::raw("DATE_SUB(`{$column}`, INTERVAL {$seconds} SECOND)");
            }

            $rows += DB::table($table)->where('tenant_id', $tenant->id)->update($updates);
        }

        foreach (SandboxTables::shiftableDates() as $table => $columns) {
            $updates = [];

            foreach ($columns as $column) {
                $updates[$column] = DB::raw("DATE_SUB(`{$column}`, INTERVAL {$days} DAY)");
            }

            $rows += DB::table($table)->where('tenant_id', $tenant->id)->update($updates);
        }

        return $rows;
    }

    /**
     * The checkout holds that lapsed while time was passing.
     *
     * Same predicate as `bookings:release-expired`, narrowed to one tenant: a
     * pending booking with no request window, older than the hold. Their
     * `created_at` moved in the shift, which is what makes them old.
     */
    private function releaseExpiredHolds(Tenant $tenant): int
    {
        $cutoff = now()->subMinutes((int) config('booking.pending_hold_minutes'));

        $expired = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Pending->value)
            ->whereNull('request_expires_at')
            ->where('created_at', '<=', $cutoff)
            ->get();

        foreach ($expired as $booking) {
            $this->bookings->cancel($booking, 'checkout_expired');
        }

        return $expired->count();
    }

    /** Same predicate as `bookings:expire-requests`, narrowed to one tenant. */
    private function declineExpiredRequests(Tenant $tenant): int
    {
        $expired = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('request_expires_at')
            ->where('request_expires_at', '<=', now())
            ->get();

        foreach ($expired as $booking) {
            $this->bookings->decline($booking, null, null, 'booking.request.expired');
        }

        return $expired->count();
    }

    /**
     * Retire unclaimed offers and pass the slot to the next person.
     *
     * `WaitlistOfferer::expireAndContinue()` takes an optional tenant id for
     * exactly this: the scheduled command still sweeps the platform, and this
     * sweeps one salon. Counting first rather than asking the offerer to report,
     * because what the owner wants to know is how many offers this press retired.
     */
    private function expireSlotOffers(Tenant $tenant): int
    {
        $due = DB::table('slot_offers')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'sent')
            ->where('expires_at', '<=', now())
            ->count();

        $this->waitlist->expireAndContinue($tenant->id);

        return $due;
    }

    /**
     * The reminders whose hour arrived while time was passing.
     *
     * This is the automation the shift alone cannot deliver, and the reason
     * this class runs anything at all — see the note at the top. The predicate
     * is `Notifier::scheduleReminder`'s, read forwards: a confirmed booking,
     * still in the future, now inside the reminder window, whose reminder has
     * not been cancelled.
     *
     * The "already reminded" test is the send log rather than a column, because
     * there is no column — the product has never needed to ask, since a delayed
     * job asks itself once by existing. Here the same booking can come due
     * again on a second press, so the `messages` row a reminder writes is what
     * stops it going out twice.
     */
    private function sendDueReminders(Tenant $tenant): int
    {
        $window = now()->addHours((int) config('booking.reminder_hours'));

        $due = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereNull('reminder_cancelled_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', $window)
            ->get();

        $sent = 0;

        foreach ($due as $booking) {
            $already = Message::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('booking_id', $booking->id)
                ->where('type', MessageType::Reminder->value)
                ->exists();

            if ($already) {
                continue;
            }

            $this->notifier->reminder($booking);
            $sent++;
        }

        return $sent;
    }
}
