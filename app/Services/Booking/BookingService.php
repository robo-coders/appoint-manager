<?php

namespace App\Services\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\SlotOfferStatus;
use App\Exceptions\BookingNotCompletableException;
use App\Exceptions\OfferUnavailableException;
use App\Exceptions\PaymentSetupFailedException;
use App\Exceptions\PaymentsNotConfiguredException;
use App\Exceptions\RequestNotPendingException;
use App\Exceptions\SlotUnavailableException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Availability\AvailabilityEngine;
use App\Services\Loyalty\Loyalty;
use App\Services\Notifications\Notifier;
use App\Services\Rebooking\RebookInterval;
use App\Services\Stripe\StripeGateway;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\AvailabilityCache;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BookingService
{
    private ?Closure $afterLock = null;

    private ?string $lastClientSecret = null;

    /**
     * `StripeGateway` is deliberately not a constructor dependency.
     *
     * Its binding refuses to resolve without Stripe credentials (AUDIT C1: the
     * alternative is a fake gateway that accepts forged webhook signatures, so
     * refusing is correct). Type-hinting it here made that refusal happen at
     * *container resolution* — so `PublicBookingController::store` died before
     * a line of its own code ran, for every tenant on the page, whether or not
     * the booking involved money at all. A salon that takes no deposits got a
     * stack trace out of a code path that never needed a gateway.
     *
     * So it is resolved at the point of use instead, inside the two places that
     * already know what to do when payments cannot be reached. See `gateway()`.
     */
    public function __construct(
        private AvailabilityEngine $engine,
        private Notifier $notifier,
        private WaitlistOfferer $waitlist,
        private Loyalty $loyalty,
    ) {}

    /**
     * Resolve the gateway, late.
     *
     * Late enough that a booking with no deposit never asks for one, and that a
     * platform with no credentials is a `PaymentsNotConfiguredException` the
     * caller can catch rather than a container failure nobody can. C1 is
     * untouched either way: this asks the same binding the same question and
     * gets the same refusal — it just asks it somewhere an answer is possible.
     *
     * Not memoised: the binding is a singleton, so this is a container lookup,
     * and reading it fresh is what lets a test swap the gateway underneath.
     */
    private function gateway(): StripeGateway
    {
        return app(StripeGateway::class);
    }

    public function lastClientSecret(): ?string
    {
        return $this->lastClientSecret;
    }

    /**
     * @internal Tests inject a competing write after lockForUpdate().
     */
    public function withAfterLock(Closure $callback): self
    {
        $this->afterLock = $callback;

        return $this;
    }

    public function needsDeposit(Tenant $tenant, Service $service, BookingSource $source): bool
    {
        if ($source === BookingSource::Manual) {
            return false;
        }

        if ($tenant->isRequestMode() && ! $tenant->request_requires_deposit) {
            return false;
        }

        return $tenant->takesDeposits() && $service->deposit_amount->amount > 0;
    }

    public function create(
        Tenant $tenant,
        Service $service,
        User $staff,
        Customer $customer,
        CarbonImmutable $startsAt,
        BookingSource $source,
        ?Subject $subject = null,
        ?BookingStatus $status = null,
        ?DepositStatus $depositStatus = null,
        ?int $waitlistEntryId = null,
        ?int $rebookIntervalDays = null,
    ): Booking {
        $startsAt = $startsAt->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);
        app(TenantContext::class)->set($tenant);

        /*
         * Loyalty, and the whole of its reach into this method.
         *
         * `enrol()` is a no-op unless the tenant has switched the feature on, so
         * for every other tenant these two lines are two early returns and
         * nothing else changes. When the reward is due the booking is free:
         * price zero, deposit zero, and `needsDeposit` false — which is why the
         * loyalty question is asked *before* `needsDeposit()` rather than
         * unpicking its answer afterwards. Nothing else about the flow moves.
         */
        $this->loyalty->enrol($tenant, $customer);
        $isReward = $this->loyalty->rewardDue($tenant, $customer);
        $needsDeposit = ! $isReward && $this->needsDeposit($tenant, $service, $source);
        $isRequest = $tenant->isRequestMode() && $source !== BookingSource::Manual;

        $booking = $this->inStaffLockedWrite(function () use ($tenant, $service, $staff, $customer, $startsAt, $endsAt, $source, $subject, $status, $depositStatus, $needsDeposit, $isRequest, $isReward, $waitlistEntryId, $rebookIntervalDays) {
            $this->lockStaffRow($tenant, $staff);

            if ($this->afterLock !== null) {
                ($this->afterLock)();
            }

            $this->assertSlotOpen($tenant, $service, $staff, $startsAt);

            $resolved = $status ?? ($isRequest || $needsDeposit ? BookingStatus::Pending : BookingStatus::Confirmed);

            $booking = new Booking;
            $booking->forceFill([
                'tenant_id' => $tenant->id,
                'staff_id' => $staff->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'subject_id' => $subject?->id,
                'waitlist_entry_id' => $waitlistEntryId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $resolved,
                'deposit_status' => $depositStatus ?? ($needsDeposit ? DepositStatus::Required : DepositStatus::None),
                // The reward is the free one. Zero here rather than a discount
                // applied later, so every screen, export and refund path that
                // reads `price_at_booking` sees the price that was charged.
                'price_at_booking' => $isReward ? 0 : $service->price->amount,
                'deposit_at_booking' => $needsDeposit ? $service->deposit_amount->amount : 0,
                'is_loyalty_reward' => $isReward,
                'source' => $source,
                'rebook_interval_days' => $rebookIntervalDays,
                'request_expires_at' => $isRequest && $resolved === BookingStatus::Pending
                    ? now()->addHours($tenant->requestExpiryHours())
                    : null,
            ]);
            $booking->save();

            if ($subject !== null && $rebookIntervalDays !== null && $rebookIntervalDays > 0) {
                app(RebookInterval::class)->remember($subject, $rebookIntervalDays);
            }

            return $booking;
        });

        // Everything below runs with no transaction open and no row locks held. The
        // staff window must never stay locked across a third-party call.
        AvailabilityCache::bust($tenant->id);

        /*
         * Spend the stamps that paid for this one, before the confirmation goes
         * out — the message quotes the counter, and a message saying "5 of 5" on
         * the appointment that used them would be a receipt for a card that has
         * already been cleared.
         */
        if ($isReward) {
            $this->loyalty->spendReward($tenant, $customer);
        }

        if ($needsDeposit && $booking->status === BookingStatus::Pending) {
            $this->attachPaymentIntent($tenant, $booking, $isRequest ? 'manual' : 'automatic');
        }

        if ($booking->status === BookingStatus::Confirmed) {
            $this->notifier->bookingConfirmed($booking);
        } elseif ($isRequest && $booking->status === BookingStatus::Pending) {
            $this->notifier->bookingRequested($booking);
        }

        return $booking;
    }

    /**
     * A pending booking with no payment intent is unpayable: the customer would be
     * shown a hold they can never complete, and it would be cancelled 15 minutes
     * later. So a Stripe failure releases the slot and is raised to the caller
     * rather than being logged and hidden.
     *
     * @throws PaymentSetupFailedException
     */
    private function attachPaymentIntent(Tenant $tenant, Booking $booking, string $captureMethod = 'automatic'): void
    {
        try {
            $intent = $this->gateway()->createPaymentIntent($tenant, $booking, $captureMethod);
        } catch (PaymentsNotConfiguredException $exception) {
            report($exception);
            $this->releaseUnpayable($booking, $tenant);

            throw PaymentSetupFailedException::notConfigured($exception);
        } catch (Throwable $exception) {
            report($exception);
            $this->releaseUnpayable($booking, $tenant);

            throw PaymentSetupFailedException::forBooking($exception);
        }

        try {
            $booking->forceFill(['stripe_payment_intent_id' => $intent['id']])->save();
        } catch (Throwable $exception) {
            report($exception);
            $this->releaseUnpayable($booking, $tenant);

            throw PaymentSetupFailedException::forBooking($exception);
        }

        $this->lastClientSecret = $intent['client_secret'];
    }

    public function approve(Booking $booking, ?User $actor = null): Booking
    {
        $tenant = $booking->tenant ?? Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);

        $outcome = DB::transaction(function () use ($booking) {
            $locked = Booking::withoutGlobalScopes()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Pending || $locked->request_expires_at === null) {
                throw RequestNotPendingException::forBooking();
            }

            return $locked;
        });

        $this->captureHeldPayment($outcome, $tenant);

        $outcome->forceFill([
            'status' => BookingStatus::Confirmed,
            'deposit_status' => $outcome->stripe_payment_intent_id ? DepositStatus::Paid : $outcome->deposit_status,
            'deposit_paid_at' => $outcome->stripe_payment_intent_id ? now() : $outcome->deposit_paid_at,
            'request_expires_at' => null,
        ])->save();

        $this->notifier->bookingConfirmed($outcome);
        $this->audit($actor, $tenant, $outcome, 'booking.request.approved');

        return $outcome->fresh();
    }

    /**
     * Mark an appointment as having happened.
     *
     * **This is new, and it is here because the status had no writer.**
     * `BookingStatus::Completed` existed and was read in four places — the
     * dashboard's takings, the rebooking suggester, the overdue list — and set
     * by nothing but the demo seeders. So "past appointments" and "completed
     * appointments" were different sets in a product whose rebooking chase reads
     * the second one, and loyalty stamps would have had nothing to count.
     *
     * Deliberately narrow. It refuses anything that is not a confirmed
     * appointment that has already started: a pending request has not been
     * accepted, a cancellation did not happen, and an appointment in three weeks
     * has not happened yet. A booking already completed is returned unchanged
     * rather than treated as an error, so a double press is not a failure.
     *
     * The loyalty stamp is not applied here. It hangs off `Booking`'s `updated`
     * hook, so an import, a support script or a later "no show / completed"
     * bulk action agrees with this method by construction rather than by
     * remembering to call the same service.
     */
    public function complete(Booking $booking, ?User $actor = null): Booking
    {
        $tenant = $booking->tenant ?? Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);

        $outcome = DB::transaction(function () use ($booking) {
            $locked = Booking::withoutGlobalScopes()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Completed) {
                return $locked;
            }

            if ($locked->status !== BookingStatus::Confirmed) {
                throw BookingNotCompletableException::notConfirmed();
            }

            if ($locked->starts_at->isFuture()) {
                throw BookingNotCompletableException::notYetStarted();
            }

            // `forceFill` and `save`, not `update`: the `updated` model hook
            // that adds the loyalty stamp needs `wasChanged('status')`, which a
            // mass update on the query builder would never produce.
            $locked->forceFill(['status' => BookingStatus::Completed])->save();

            return $locked;
        });

        $this->audit($actor, $tenant, $outcome, 'booking.completed');

        return $outcome->fresh();
    }

    /**
     * Mark an appointment as missed.
     *
     * **This is new, and it is here for the same reason `complete()` is.**
     * `BookingStatus::NoShow` existed as an enum case and the dashboard's
     * no-show rate read it, but nothing in the app could write it — the stat was
     * structurally zero for every tenant, forever, and the only rows that ever
     * carried the status came out of the demo seeder.
     *
     * Same eligibility as `complete()`, deliberately: a confirmed appointment
     * whose start time has passed. A pending request was never accepted, a
     * cancellation is a different thing that happened, and an appointment on
     * Thursday cannot have been missed yet. Already a no-show is returned
     * unchanged rather than treated as an error, so a double press is not a
     * failure.
     *
     * No loyalty stamp, and no loyalty refund. The stamp hook only fires on
     * `Completed`, so a missed appointment earns nothing — which is the point of
     * stamping at completion rather than at booking. And a missed *reward*
     * booking stays spent: the slot was held and nobody else could have it.
     *
     * ## The hour is freed, so it is offered
     *
     * A missed appointment leaves exactly the same hole in the day as a
     * cancellation does, and it used to be the only way of leaving one that
     * told nobody. `cancel()`, `decline()` and `reschedule()` all hand the
     * vacated window to `WaitlistOfferer::offerForBooking()`; this does the
     * same, through the same call, so the offer rows, the batch size, the TTL
     * and the wording are whatever they already are for every other freed slot.
     * Nothing here composes a message — the customer being texted is being told
     * a slot opened, and *why* it opened is not their business.
     *
     * Placed after the transaction commits, like every other caller: the blast
     * writes offer rows and queues SMS, and none of that may run inside a lock
     * on the booking row.
     *
     * The `already` flag is why the transaction now returns a pair. The
     * idempotent second press returns early with the status unchanged, and it
     * must not put a second round of offers out for a slot that was already
     * offered when it was first marked.
     */
    public function markNoShow(Booking $booking, ?User $actor = null): Booking
    {
        $tenant = $booking->tenant ?? Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);

        $outcome = DB::transaction(function () use ($booking) {
            $locked = Booking::withoutGlobalScopes()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::NoShow) {
                return ['booking' => $locked, 'already' => true];
            }

            if ($locked->status !== BookingStatus::Confirmed) {
                throw BookingNotCompletableException::noShowNotConfirmed();
            }

            if ($locked->starts_at->isFuture()) {
                throw BookingNotCompletableException::noShowNotYetStarted();
            }

            $locked->forceFill(['status' => BookingStatus::NoShow])->save();

            return ['booking' => $locked, 'already' => false];
        });

        $missed = $outcome['booking'];

        $this->audit($actor, $tenant, $missed, 'booking.no_show');

        if (! $outcome['already']) {
            $this->waitlist->offerForBooking($missed);
        }

        return $missed->fresh();
    }

    public function decline(Booking $booking, ?string $reason = null, ?User $actor = null, string $action = 'booking.request.declined'): Booking
    {
        $tenant = $booking->tenant ?? Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);

        $outcome = DB::transaction(function () use ($booking, $reason) {
            $locked = Booking::withoutGlobalScopes()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BookingStatus::Pending || $locked->request_expires_at === null) {
                throw RequestNotPendingException::forBooking();
            }

            $locked->forceFill([
                'status' => BookingStatus::Declined,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'reminder_cancelled_at' => now(),
                'request_expires_at' => null,
            ])->save();

            return $locked;
        });

        $this->voidHeldPayment($outcome, $tenant);

        AvailabilityCache::bust($tenant->id);
        $this->notifier->bookingDeclined($outcome, $reason);
        $this->waitlist->offerForBooking($outcome);
        $this->audit($actor, $tenant, $outcome, $action, $reason !== null ? ['reason' => $reason] : []);

        return $outcome->fresh();
    }

    private function captureHeldPayment(Booking $booking, Tenant $tenant): void
    {
        if (! $booking->stripe_payment_intent_id || ! $tenant->stripe_account_id) {
            return;
        }

        $this->gateway()->capturePaymentIntent(
            (string) $booking->stripe_payment_intent_id,
            (string) $tenant->stripe_account_id,
        );
    }

    private function voidHeldPayment(Booking $booking, Tenant $tenant): void
    {
        if (! $booking->stripe_payment_intent_id || ! $tenant->stripe_account_id) {
            return;
        }

        try {
            $this->gateway()->cancelPaymentIntent(
                (string) $booking->stripe_payment_intent_id,
                (string) $tenant->stripe_account_id,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function audit(?User $actor, Tenant $tenant, Booking $booking, string $action, array $extra = []): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'target_tenant_id' => $tenant->id,
            'action' => $action,
            'meta' => array_merge(['booking_id' => $booking->id], $extra),
        ]);
    }

    /**
     * Give the slot straight back rather than leaving a hold nobody can pay for.
     * Quiet on purpose: the customer is about to be told directly, and they were
     * never charged, so a "your booking is cancelled" text would be nonsense.
     */
    private function releaseUnpayable(Booking $booking, Tenant $tenant): void
    {
        try {
            $booking->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => 'payment_setup_failed',
                'reminder_cancelled_at' => now(),
            ])->save();

            AvailabilityCache::bust($tenant->id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Cancel, then refund, then tell people — in that order, each step committed
     * before the next begins.
     *
     * The refund used to run inside the transaction, which meant a failure in any
     * later step (an SMS, a waitlist blast) rolled the database back after Stripe
     * had already moved the money: refunded in Stripe, still confirmed and still
     * paid here, with nobody told. Money leaves the account exactly once, and only
     * after the row that authorises it is durable.
     */
    public function cancel(Booking $booking, ?string $reason = null, bool $offerWaitlist = true): Booking
    {
        $tenant = $booking->tenant ?? Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);

        $outcome = DB::transaction(function () use ($booking, $tenant, $reason) {
            $locked = Booking::withoutGlobalScopes()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Cancelled) {
                return ['booking' => $locked, 'refund' => false, 'already' => true];
            }

            $refundable = $locked->deposit_status === DepositStatus::Paid
                && $locked->stripe_payment_intent_id
                && $tenant->stripe_account_id
                && $this->outsideRefundWindow($tenant, $locked);

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'reminder_cancelled_at' => now(),
                // Marked before the call so a crash mid-refund is visible as an
                // owed refund rather than as a booking that was never refunded.
                'deposit_status' => $refundable ? DepositStatus::RefundPending : $locked->deposit_status,
            ])->save();

            return ['booking' => $locked, 'refund' => $refundable, 'already' => false];
        });

        $booking = $outcome['booking'];

        if ($outcome['already']) {
            return $booking;
        }

        /*
         * Give the stamps back before anything else runs.
         *
         * A cancelled reward booking used to leave the customer's card empty and
         * the reward gone: `spendReward()` clears it at creation, and nothing
         * ever undid that. Placed after the transaction — like `spendReward()`
         * in `create()` — because the enrolment write is its own concern and
         * must not extend the lock on the booking row. Guarded by the `already`
         * return above, so a second cancel cannot refund a second time.
         */
        if ($booking->is_loyalty_reward) {
            $customer = $booking->customer ?? Customer::withoutGlobalScopes()->find($booking->customer_id);

            if ($customer !== null) {
                $this->loyalty->refundReward($tenant, $customer);
            }
        }

        $refundStatus = $this->settleRefund($booking, $tenant, $outcome['refund']);

        AvailabilityCache::bust($tenant->id);
        $this->notifier->bookingCancelled($booking, $refundStatus);

        if ($offerWaitlist) {
            $this->waitlist->offerForBooking($booking);
        }

        $booking->refund_message = $refundStatus;
        $booking->was_refunded = $booking->deposit_status === DepositStatus::Refunded;

        return $booking;
    }

    /**
     * Issue the refund with no transaction open, then record what happened.
     */
    private function settleRefund(Booking $booking, Tenant $tenant, bool $refundable): string
    {
        if (! $refundable) {
            return $booking->deposit_status === DepositStatus::Paid
                ? 'No refund — inside the cancellation window.'
                : 'No deposit to refund.';
        }

        try {
            $this->gateway()->refundPaymentIntent(
                (string) $booking->stripe_payment_intent_id,
                (string) $tenant->stripe_account_id,
            );
        } catch (Throwable $exception) {
            report($exception);

            // Left as RefundPending on purpose: this is a refund we owe and have
            // not yet made, and it needs to stay visible until it is settled.
            return 'Your refund is being processed.';
        }

        $booking->forceFill(['deposit_status' => DepositStatus::Refunded])->save();

        return 'Your deposit will be refunded.';
    }

    public function reschedule(Booking $booking, CarbonImmutable $startsAt, User $staff): Booking
    {
        $tenant = $booking->tenant ?? Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);
        $service = $booking->service ?? Service::withoutGlobalScopes()->findOrFail($booking->service_id);
        $startsAt = $startsAt->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);
        $oldStart = CarbonImmutable::parse($booking->starts_at)->utc();
        $oldEnd = CarbonImmutable::parse($booking->ends_at)->utc();
        $oldStaffId = $booking->staff_id;

        $booking = $this->inStaffLockedWrite(function () use ($booking, $tenant, $service, $staff, $startsAt, $endsAt) {
            $this->lockStaffRow($tenant, $staff);
            $this->assertSlotOpen($tenant, $service, $staff, $startsAt, ignoreBookingId: $booking->id);

            $booking->forceFill([
                'staff_id' => $staff->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                // The old reminder was scheduled against the old time. Retire it and
                // let the new one be scheduled below.
                'reminder_cancelled_at' => now(),
            ])->save();

            return $booking;
        });

        AvailabilityCache::bust($tenant->id);

        $booking->forceFill(['reminder_cancelled_at' => null])->save();
        $this->notifier->bookingRescheduled($booking);

        $freed = clone $booking;
        $freed->staff_id = $oldStaffId;
        $freed->starts_at = $oldStart;
        $freed->ends_at = $oldEnd;
        $this->waitlist->offerForBooking($freed);

        return $booking;
    }

    public function claimOffer(SlotOffer $offer): Booking
    {
        $offer = SlotOffer::withoutGlobalScopes()->findOrFail($offer->id);
        $tenant = Tenant::query()->findOrFail($offer->tenant_id);
        app(TenantContext::class)->set($tenant);
        $offer->load(['waitlistEntry.customer', 'waitlistEntry.subject', 'service', 'staff']);

        if ($offer->status === SlotOfferStatus::Expired || $offer->expires_at?->isPast()) {
            throw OfferUnavailableException::expired();
        }

        if (! $offer->isClaimable()) {
            throw OfferUnavailableException::taken();
        }

        $entry = $offer->waitlistEntry;
        $service = $offer->service;
        $staff = $offer->staff;
        $customer = $entry->customer;
        $startsAt = CarbonImmutable::parse($offer->starts_at)->utc();

        try {
            $booking = $this->create(
                $tenant,
                $service,
                $staff,
                $customer,
                $startsAt,
                BookingSource::Online,
                $entry->subject,
                waitlistEntryId: $entry->id,
            );
        } catch (SlotUnavailableException) {
            throw OfferUnavailableException::taken();
        }

        $offer->forceFill([
            'status' => SlotOfferStatus::Claimed,
            'booking_id' => $booking->id,
        ])->save();

        $entry->forceFill(['is_active' => false])->save();

        $siblings = SlotOffer::withoutGlobalScopes()
            ->with('waitlistEntry.customer')
            ->where('tenant_id', $tenant->id)
            ->where('staff_id', $staff->id)
            ->where('starts_at', $offer->starts_at)
            ->where('id', '!=', $offer->id)
            ->where('status', SlotOfferStatus::Sent->value)
            ->get();

        foreach ($siblings as $sibling) {
            $sibling->forceFill(['status' => SlotOfferStatus::Superseded])->save();
            $other = WaitlistEntry::withoutGlobalScopes()->find($sibling->waitlist_entry_id);
            $gone = $other ? Customer::withoutGlobalScopes()->find($other->customer_id) : null;
            if ($gone) {
                $this->notifier->waitlistGone($tenant, $gone);
            }
        }

        return $booking;
    }

    public function refundPreview(Tenant $tenant, Booking $booking): string
    {
        if ($booking->deposit_status !== DepositStatus::Paid) {
            return 'No deposit to refund.';
        }

        return $this->outsideRefundWindow($tenant, $booking)
            ? 'Your deposit will be refunded.'
            : 'No refund — inside the cancellation window.';
    }

    public function outsideRefundWindow(Tenant $tenant, Booking $booking): bool
    {
        $hours = (int) data_get($tenant->settings, 'booking.refund_window_hours', config('booking.refund_window_hours'));

        return CarbonImmutable::parse($booking->starts_at)->utc()->subHours($hours)->isFuture();
    }

    public function canReschedule(Tenant $tenant, Booking $booking): bool
    {
        if (! (bool) data_get($tenant->settings, 'booking.customer_can_reschedule', true)) {
            return false;
        }

        return $this->outsideRefundWindow($tenant, $booking)
            && in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Pending], true);
    }

    public function canCancel(Tenant $tenant, Booking $booking): bool
    {
        if (! (bool) data_get($tenant->settings, 'booking.customer_can_cancel', true)) {
            return false;
        }

        return in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Pending], true)
            && CarbonImmutable::parse($booking->starts_at)->utc()->isFuture();
    }

    /**
     * Serialise writes for one staff member by locking their `users` row.
     *
     * The previous approach selected overlapping bookings `FOR UPDATE`. That
     * window is usually empty (first booking of the day), so InnoDB takes a
     * gap lock. Two transactions can both hold the gap, both INSERT into it,
     * and InnoDB kills one with SQLSTATE 40001. The staff row exists, so this
     * is a row lock: the loser waits, then `assertSlotOpen()` sees the slot
     * gone and throws `SlotUnavailableException`.
     */
    private function lockStaffRow(Tenant $tenant, User $staff): void
    {
        User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($staff->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inStaffLockedWrite(callable $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (DeadlockException $exception) {
            report($exception);

            throw SlotUnavailableException::forSlot();
        } catch (QueryException $exception) {
            if ($this->isDeadlock($exception)) {
                report($exception);

                throw SlotUnavailableException::forSlot();
            }

            throw $exception;
        }
    }

    private function isDeadlock(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === '40001') {
            return true;
        }

        return ($exception->errorInfo[0] ?? null) === '40001';
    }

    private function assertSlotOpen(
        Tenant $tenant,
        Service $service,
        User $staff,
        CarbonImmutable $startsAt,
        ?int $ignoreBookingId = null,
    ): void {
        $dayFrom = $startsAt->timezone($tenant->timezone)->startOfDay()->utc();
        $dayTo = $startsAt->timezone($tenant->timezone)->addDay()->startOfDay()->utc();
        $slots = $this->engine->slotsFor($tenant, $service, $dayFrom, $dayTo, $staff, $ignoreBookingId);

        if (! $slots->containsStart($startsAt) || ! in_array($staff->id, $slots->staffIdsFor($startsAt), true)) {
            throw SlotUnavailableException::forSlot();
        }
    }
}
