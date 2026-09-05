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

/**
 * One member of staff's calendar, as a subscribable `.ics` feed.
 *
 * **Unauthenticated, deliberately, and this is the standard shape.** There is no
 * staff login in this product, and a calendar client — iOS, Google Calendar,
 * Outlook — cannot sign in to one even where it exists: it fetches a URL on a
 * timer with no cookie jar. So the URL *is* the credential, which is how every
 * calendar subscription on the internet works. What that costs and what is done
 * about it:
 *
 *   - The token is 128 bits of `random_bytes`, unique across all tenants, and
 *     not guessable. See `User::calendarToken()`.
 *   - It is revocable. The owner regenerates it from settings and the old URL
 *     stops resolving on the next poll.
 *   - **The feed carries the minimum that makes a diary useful and nothing
 *     more.** Customer's name, the service, the time, and the member of staff.
 *     No phone number, no email, no address, no price, no deposit status, no
 *     notes about the animal. A leaked link is then a leak of who is coming in
 *     on Thursday, not of a customer list somebody can ring.
 *   - `X-Robots-Tag: noindex` and `Cache-Control: private`, so a crawler that
 *     somehow finds the URL does not publish it and a shared proxy does not keep
 *     it.
 *
 * **Confirmed and upcoming only.** A cancelled appointment must disappear from
 * the phone rather than linger, a pending request is not yet an appointment, and
 * the past is not what a calendar is for — a staff member scrolling back is
 * looking at their own calendar's history, and this feed is not the record.
 *
 * `WINDOW_DAYS` bounds the query so a salon with three years of forward bookings
 * does not serve a megabyte to a phone every fifteen minutes.
 */
class StaffCalendarController extends Controller
{
    /** How far ahead the feed reaches. */
    private const WINDOW_DAYS = 120;

    /** Five minutes. "Live" for a calendar client that polls every fifteen. */
    private const CACHE_SECONDS = 300;

    public function __invoke(Request $request, string $token): Response
    {
        /*
         * `withoutGlobalScopes()` because there is no tenant context on this
         * route and there cannot be: the request carries no session and no user.
         * The token is the only identifier, and it is unique across tenants — so
         * the row it finds *establishes* the context rather than being filtered
         * by it. `TenantContext` is set from the row before anything else is
         * read, so every query below is scoped to the right salon.
         */
        $staff = User::withoutGlobalScopes()
            ->whereNotNull('calendar_token')
            ->where('calendar_token', $token)
            ->first();

        // 404, not 403. A wrong token must be indistinguishable from a URL that
        // was never issued.
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
                /*
                 * Stable for the life of the booking, and unique across every
                 * calendar the reader subscribes to. The booking's own
                 * `public_token` is already a uuid, and the host makes it a
                 * globally scoped identifier the way the RFC intends.
                 */
                uid: $booking->public_token.'@'.$request->getHost(),
                startsAt: $booking->starts_at,
                endsAt: $booking->ends_at,
                // Who and what, in that order: a staff member scanning a week
                // is looking for the name.
                summary: $customer.' — '.$service,
                description: $service.' with '.$staff->name.'. '.$tenant->name.'.',
                updatedAt: $booking->updated_at,
            );
        }

        return response($calendar->render(), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            /*
             * `inline`, and a filename. Safari on iOS decides whether to offer
             * "subscribe" partly on the content type; a `Content-Disposition` of
             * `attachment` makes it a download instead, which is the wrong
             * behaviour for a subscription.
             */
            'Content-Disposition' => 'inline; filename="'.str()->slug($staff->name).'.ics"',
            'Cache-Control' => 'private, max-age='.self::CACHE_SECONDS,
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
