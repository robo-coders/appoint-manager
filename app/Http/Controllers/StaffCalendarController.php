<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ICalendar;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffCalendarController extends Controller
{
    private const WINDOW_DAYS = 120;

    private const CACHE_SECONDS = 300;

    public function __invoke(Request $request, string $token): Response
    {
        $staff = User::withoutGlobalScopes()
            ->whereNotNull('calendar_token')
            ->where('calendar_token', $token)
            ->first();

        abort_if($staff === null || $staff->tenant_id === null, 404);

        $tenant = Tenant::query()->findOrFail($staff->tenant_id);
        app(TenantContext::class)->set($tenant);

        $bookings = Booking::query()
            ->where('staff_id', $staff->id)
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>=', now()->subHours(12))
            ->where('starts_at', '<=', now()->addDays(self::WINDOW_DAYS))
            ->with(['customer', 'service'])
            ->orderBy('starts_at')
            ->get();

        $calendar = new ICalendar($staff->name.' — '.$tenant->name, $tenant->timezone);

        foreach ($bookings as $booking) {
            $customer = $booking->customer?->name ?? 'Customer';
            $service = $booking->service?->name ?? 'Appointment';

            $calendar->event(
                uid: $booking->public_token.'@'.$request->getHost(),
                startsAt: $booking->starts_at,
                endsAt: $booking->ends_at,
                summary: $customer.' — '.$service,
                description: $service.' with '.$staff->name.'. '.$tenant->name.'.',
                updatedAt: $booking->updated_at,
            );
        }

        return response($calendar->render(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.str()->slug($staff->name).'.ics"',
            'Cache-Control' => 'private, max-age='.self::CACHE_SECONDS,
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
