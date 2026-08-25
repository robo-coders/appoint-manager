<?php

namespace App\Http\Controllers;

use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Availability\AvailabilityEngine;
use App\Services\Booking\BookingService;
use App\Support\BookingPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManageBookingController extends Controller
{
    public function show(string $token, BookingService $bookings): View
    {
        $booking = $this->booking($token);
        $tenant = $booking->tenant;

        return view('manage-booking', [
            'tenant' => $tenant,
            'props' => [
                'booking' => BookingPayload::toArray($booking, $tenant->timezone),
                'tenant' => [
                    'name' => $tenant->name,
                    'timezone' => $tenant->timezone,
                    'address' => trim($tenant->address_line_1.' '.$tenant->city.' '.$tenant->postcode),
                ],
                'can_cancel' => $bookings->canCancel($tenant, $booking),
                'can_reschedule' => $bookings->canReschedule($tenant, $booking),
                'refund_preview' => $bookings->refundPreview($tenant, $booking),
                'urls' => [
                    'cancel' => route('booking.manage.cancel', $booking->public_token),
                    'reschedule' => route('booking.manage.reschedule', $booking->public_token),
                    'availability' => route('booking.manage.availability', $booking->public_token),
                ],
            ],
        ]);
    }

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
        $slots = $engine->slotsFor($tenant, $booking->service, $rangeFrom, $rangeTo);

        return response()->json([
            'slots' => collect($slots->toArray())->map(function (array $slot) use ($tenant) {
                $start = CarbonImmutable::parse($slot['starts_at']);

                return [
                    'starts_at' => $slot['starts_at'],
                    'starts_at_local' => $start->timezone($tenant->timezone)->format('Y-m-d H:i'),
                    'staff_ids' => $slot['staff_ids'],
                ];
            })->values(),
        ]);
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

    private function booking(string $token): Booking
    {
        $booking = Booking::withoutGlobalScopes()
            ->with(['tenant', 'service', 'staff', 'customer', 'subject'])
            ->where('public_token', $token)
            ->first();

        abort_if($booking === null, 404);

        return $booking;
    }
}
