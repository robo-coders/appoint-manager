<?php

namespace App\Http\Controllers;

use App\Enums\DepositStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Availability\AvailabilityEngine;
use App\Services\Booking\BookingService;
use App\Support\BookingPayload;
use App\Support\ReturningCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ManageBookingController extends Controller
{
    public function show(Request $request, string $token, BookingService $bookings): Response
    {
        $booking = $this->booking($token);
        $tenant = $booking->tenant;
        $tz = $tenant->timezone;
        $starts = CarbonImmutable::parse($booking->starts_at)->timezone($tz);

        $response = response()->view('manage-booking', [
            'tenant' => $tenant,
            'props' => [
                'booking' => BookingPayload::toArray($booking, $tz, [
                    // The same three strings the booking page's proposal uses,
                    // built the same way, so the two screens cannot drift into
                    // describing one appointment two different ways.
                    'day_label' => $starts->format('l j F'),
                    'time' => $starts->format('H:i'),
                    'cost_line' => $this->costLine($booking, $tenant),
                    'free_until' => $this->freeUntil($booking, $tenant, $bookings),
                    'context' => $this->context($booking),
                ]),
                'tenant' => [
                    'name' => $tenant->name,
                    'timezone' => $tz,
                    'address' => trim($tenant->address_line_1.' '.$tenant->city.' '.$tenant->postcode),
                    'phone' => $tenant->phone,
                ],
                'can_cancel' => $bookings->canCancel($tenant, $booking),
                'can_reschedule' => $bookings->canReschedule($tenant, $booking),
                'cancel_consequence' => $this->cancelConsequence($booking, $tenant, $bookings),
                'urls' => [
                    'cancel' => route('booking.manage.cancel', $booking->public_token),
                    'reschedule' => route('booking.manage.reschedule', $booking->public_token),
                    'availability' => route('booking.manage.availability', $booking->public_token),
                ],
            ],
        ]);

        /*
         * This is the moment we know who somebody is: they opened the link from
         * their own confirmation. Remembering it is what lets the booking page
         * greet them next time with their own service, their own groomer and
         * their own rhythm instead of a service picker. See ReturningCustomer
         * for why holding the token in a cookie adds no exposure.
         */
        return $response->withCookie(
            ReturningCustomer::remember($booking->public_token, $request->secure())
        );
    }

    /**
     * The same day grid the public page's picker draws, in the same shape.
     *
     * Unavailable times are included and flagged rather than filtered out —
     * see `AvailabilityEngine::gridFor()`. The two endpoints returning different
     * shapes is how `SlotPicker` ended up unable to be shared.
     */
    public function availability(string $token, Request $request, AvailabilityEngine $engine): JsonResponse
    {
        $booking = $this->booking($token);
        $tenant = $booking->tenant;
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1, 422);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1, 422);

        $rangeFrom = CarbonImmutable::parse($from.' 00:00:00', $tenant->timezone)->utc();
        $rangeTo = CarbonImmutable::parse($to.' 00:00:00', $tenant->timezone)->addDay()->utc();

        // This booking must not block the slot it is being moved within.
        $free = $engine->slotsFor($tenant, $booking->service, $rangeFrom, $rangeTo, null, $booking->id);
        $grid = $engine->gridFor($tenant, $booking->service, $rangeFrom, $rangeTo);

        $freeIds = [];
        foreach ($free as $slot) {
            $freeIds[$slot->startsAt->utc()->getTimestamp()] = $slot->staffIds;
        }

        $days = [];
        $cursor = CarbonImmutable::parse($from, $tenant->timezone)->startOfDay();
        $last = CarbonImmutable::parse($to, $tenant->timezone)->startOfDay();

        while ($cursor->lte($last)) {
            $days[$cursor->toDateString()] = [];
            $cursor = $cursor->addDay();
        }

        foreach ($grid as $slot) {
            $local = $slot->startsAt->timezone($tenant->timezone);
            $stamp = $slot->startsAt->utc()->getTimestamp();
            $available = array_key_exists($stamp, $freeIds);

            $days[$local->toDateString()][] = [
                'starts_at' => $slot->startsAt->utc()->toIso8601String(),
                'starts_at_local' => $local->format('H:i'),
                'staff_ids' => $available ? $freeIds[$stamp] : [],
                'available' => $available,
                'half' => $local->hour < 12 ? 'am' : 'pm',
            ];
        }

        return response()->json(['days' => $days]);
    }

    public function cancel(string $token, BookingService $bookings): JsonResponse
    {
        $booking = $this->booking($token);
        $preview = $bookings->refundPreview($booking->tenant, $booking);
        abort_unless($bookings->canCancel($booking->tenant, $booking), 422, 'This booking cannot be cancelled.');

        $updated = $bookings->cancel($booking);

        return response()->json([
            'status' => $updated->status->value,
            'refund' => $preview,
        ]);
    }

    public function reschedule(string $token, Request $request, BookingService $bookings): JsonResponse
    {
        $booking = $this->booking($token);
        abort_unless($bookings->canReschedule($booking->tenant, $booking), 422, 'This booking cannot be moved.');

        $startsAt = CarbonImmutable::parse($request->string('starts_at')->toString())->utc();
        $staffId = $request->integer('staff_id');
        $staff = User::withoutGlobalScopes()
            ->where('tenant_id', $booking->tenant_id)
            ->findOrFail($staffId ?: $booking->staff_id);

        try {
            $updated = $bookings->reschedule($booking, $startsAt, $staff);
        } catch (SlotUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'status' => $updated->status->value,
            'starts_at' => $updated->starts_at?->utc()->toIso8601String(),
        ]);
    }

    /**
     * The consequence, stated as the thing that will happen to their money.
     *
     * This is the label on the row that opens the cancel dialog, so a customer
     * decides *knowing* — rather than pressing "Cancel booking", reading "are
     * you sure?", and finding out about the deposit in the confirmation email.
     */
    private function cancelConsequence(Booking $booking, Tenant $tenant, BookingService $bookings): string
    {
        if ($booking->deposit_status !== DepositStatus::Paid) {
            return 'Cancel this appointment';
        }

        $deposit = $booking->deposit_at_booking->formatted();

        return $bookings->outsideRefundWindow($tenant, $booking)
            ? 'Cancel and refund '.$deposit
            : 'Cancel — the '.$deposit.' deposit is not refunded this close to the appointment';
    }

    private function costLine(Booking $booking, Tenant $tenant): string
    {
        $price = $booking->price_at_booking->formatted();

        if ($booking->deposit_status === DepositStatus::Paid) {
            return $price.' total, '.$booking->deposit_at_booking->formatted().' deposit paid';
        }

        if ($booking->deposit_status === DepositStatus::Required) {
            return $price.' total, '.$booking->deposit_at_booking->formatted().' deposit still due';
        }

        return $price.', pay on the day';
    }

    private function freeUntil(Booking $booking, Tenant $tenant, BookingService $bookings): ?string
    {
        if (! $bookings->outsideRefundWindow($tenant, $booking)) {
            return null;
        }

        $hours = (int) data_get($tenant->settings, 'booking.refund_window_hours', config('booking.refund_window_hours'));

        return CarbonImmutable::parse($booking->starts_at)
            ->utc()
            ->subHours($hours)
            ->timezone($tenant->timezone)
            ->format('l j F');
    }

    /** "Full groom for Bramble · 90 min with Ana" — the booking page's line, minus the reason. */
    private function context(Booking $booking): string
    {
        $service = $booking->service?->name ?? 'Appointment';
        $subject = $booking->subject?->name;
        $staff = explode(' ', trim((string) $booking->staff?->name))[0];
        $minutes = $booking->service?->duration_minutes;

        return implode(' · ', array_filter([
            $subject === null ? $service : $service.' for '.$subject,
            $minutes === null ? null : $minutes.' min with '.$staff,
        ]));
    }

    private function booking(string $token): Booking
    {
        $booking = Booking::withoutGlobalScopes()
            ->with([
                'tenant',
                'service' => fn ($query) => $query->withoutGlobalScopes(),
                'staff' => fn ($query) => $query->withoutGlobalScopes(),
                'customer' => fn ($query) => $query->withoutGlobalScopes(),
                'subject' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('public_token', $token)
            ->first();

        abort_if($booking === null, 404);

        return $booking;
    }
}
