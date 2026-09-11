<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
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

        if ($booking === null) {
            return $this->inactive();
        }

        $tenant = $booking->tenant;
        $tz = $tenant->timezone;
        $starts = CarbonImmutable::parse($booking->starts_at)->timezone($tz);
        $state = $this->state($booking);

        $response = response()->view('manage-booking', [
            'tenant' => $tenant,
            'headerCode' => $this->salonCode($tenant),
            'props' => [
                'booking' => BookingPayload::toArray($booking, $tz, [
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
                    'code' => $this->salonCode($tenant),
                ],
                'state' => $state,
                'horizon_days' => (int) config('booking.horizon_days'),
                'can_cancel' => $state === 'live' && $bookings->canCancel($tenant, $booking),
                'can_reschedule' => $state === 'live' && $bookings->canReschedule($tenant, $booking),
                'cancel_consequence' => $this->cancelConsequence($booking, $tenant, $bookings),
                'urls' => [
                    'cancel' => route('booking.manage.cancel', $booking->public_token),
                    'reschedule' => route('booking.manage.reschedule', $booking->public_token),
                    'availability' => route('booking.manage.availability', $booking->public_token),
                ],
            ],
        ]);

        return $response->withCookie(
            ReturningCustomer::remember($booking->public_token, $request->secure())
        );
    }

    public function availability(string $token, Request $request, AvailabilityEngine $engine): JsonResponse
    {
        $booking = $this->booking($token);

        if ($booking === null) {
            return $this->gone();
        }

        $tenant = $booking->tenant;
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1, 422);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1, 422);

        $rangeFrom = CarbonImmutable::parse($from.' 00:00:00', $tenant->timezone)->utc();
        $rangeTo = CarbonImmutable::parse($to.' 00:00:00', $tenant->timezone)->addDay()->utc();

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

        if ($booking === null) {
            return $this->gone();
        }

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

        if ($booking === null) {
            return $this->gone();
        }

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

    private function cancelConsequence(Booking $booking, Tenant $tenant, BookingService $bookings): string
    {
        if ($booking->deposit_status !== DepositStatus::Paid || $booking->deposit_at_booking->amount === 0) {
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

    private function booking(string $token): ?Booking
    {
        $floor = (int) config('booking_management.token_length');

        if (preg_match('/^[A-Za-z0-9-]{'.$floor.',128}$/', $token) !== 1) {
            return null;
        }

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

        return $booking;
    }

    private function inactive(): Response
    {
        return response()->view('booking-link-inactive', [], 404);
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => 'This booking link is no longer active.'], 404);
    }

    private function state(Booking $booking): string
    {
        if ($booking->status === BookingStatus::Cancelled) {
            return 'cancelled';
        }

        if (in_array($booking->status, [BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Declined], true)) {
            return 'finished';
        }

        return CarbonImmutable::parse($booking->starts_at)->utc()->isPast() ? 'finished' : 'live';
    }

    private function salonCode(Tenant $tenant): ?string
    {
        $outward = strtoupper(trim(explode(' ', trim((string) $tenant->postcode))[0] ?? ''));

        if ($outward === '') {
            return null;
        }

        $initials = collect(preg_split('/[\s-]+/', trim((string) $tenant->city), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $word) => strtoupper($word[0]))
            ->implode('');

        return $initials === '' ? $outward : $initials.' · '.$outward;
    }
}
