<?php

namespace App\Services\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\SlotOfferStatus;
use App\Exceptions\OfferUnavailableException;
use App\Exceptions\PaymentSetupFailedException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Availability\AvailabilityEngine;
use App\Services\Notifications\Notifier;
use App\Services\Stripe\StripeGateway;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\AvailabilityCache;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BookingService
{
    private ?Closure $afterLock = null;

    public function __construct(
        private AvailabilityEngine $engine,
        private StripeGateway $stripe,
        private Notifier $notifier,
        private WaitlistOfferer $waitlist,
    ) {}

    private ?string $lastClientSecret = null;

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
        return $source !== BookingSource::Manual
            && $tenant->takesDeposits()
            && $service->deposit_amount->amount > 0;
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
    ): Booking {
        $startsAt = $startsAt->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);
        $needsDeposit = $this->needsDeposit($tenant, $service, $source);
        app(TenantContext::class)->set($tenant);

        $booking = DB::transaction(function () use ($tenant, $service, $staff, $customer, $startsAt, $endsAt, $source, $subject, $status, $depositStatus, $needsDeposit, $waitlistEntryId) {
            $this->lockStaffWindow($tenant, $staff, $startsAt, $endsAt, $service->buffer_minutes);

            if ($this->afterLock !== null) {
                ($this->afterLock)();
            }

            $this->assertSlotOpen($tenant, $service, $staff, $startsAt);

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
                'status' => $status ?? ($needsDeposit ? BookingStatus::Pending : BookingStatus::Confirmed),
                'deposit_status' => $depositStatus ?? ($needsDeposit ? DepositStatus::Required : DepositStatus::None),
                'price_at_booking' => $service->price->amount,
                'deposit_at_booking' => $needsDeposit ? $service->deposit_amount->amount : 0,
                'source' => $source,
            ]);
            $booking->save();

            return $booking;
        });

        // Everything below runs with no transaction open and no row locks held. The
        // staff window must never stay locked across a third-party call.
        AvailabilityCache::bust($tenant->id);

        if ($needsDeposit && $booking->status === BookingStatus::Pending) {
            $this->attachPaymentIntent($tenant, $booking);
        }

        if ($booking->status === BookingStatus::Confirmed) {
            $this->notifier->bookingConfirmed($booking);
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
    private function attachPaymentIntent(Tenant $tenant, Booking $booking): void
    {
        try {
            $intent = $this->stripe->createPaymentIntent($tenant, $booking);
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
            $this->stripe->refundPaymentIntent(
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

        $booking = DB::transaction(function () use ($booking, $tenant, $service, $staff, $startsAt, $endsAt) {
            $this->lockStaffWindow($tenant, $staff, $startsAt, $endsAt, $service->buffer_minutes);
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

    private function lockStaffWindow(Tenant $tenant, User $staff, CarbonImmutable $startsAt, CarbonImmutable $endsAt, int $buffer): void
    {
        Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('staff_id', $staff->id)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->where('starts_at', '<', $endsAt->addMinutes($buffer))
            ->where('ends_at', '>', $startsAt->subMinutes($buffer))
            ->lockForUpdate()
            ->get();
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
