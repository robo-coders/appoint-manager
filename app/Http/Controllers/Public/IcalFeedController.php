<?php

namespace App\Http\Controllers\Public;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\CalendarFeedToken;
use App\Models\Tenant;
use App\Services\IcalFeedBuilder;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Throwable;

class IcalFeedController extends Controller
{
    public function __construct(private readonly IcalFeedBuilder $builder) {}

    public function show(string $tenantSlug, string $token): Response
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        abort_if($tenant === null, 404);

        $feed = CalendarFeedToken::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('token', $token)
            ->first();

        abort_if($feed === null, 404);

        if ($feed->isRevoked()) {
            return response('This calendar link has been regenerated and is no longer valid.', 410, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        abort_if($feed->scope === CalendarFeedToken::SCOPE_STAFF && $feed->staff_id === null, 404);

        app(TenantContext::class)->set($tenant);

        $this->recordPull($feed);

        $body = $this->builder->build(
            $this->calendarName($tenant, $feed),
            (string) $tenant->timezone,
            $this->bookings($feed),
            $this->contentMode($tenant),
        );

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.str()->slug((string) config('product.name')).'.ics"',
            'Cache-Control' => 'private, max-age='.(int) config('calendar_sync.ics_cache_seconds'),
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * @return Collection<int, Booking>
     */
    private function bookings(CalendarFeedToken $feed): Collection
    {
        return Booking::query()
            ->when(
                $feed->scope === CalendarFeedToken::SCOPE_STAFF,
                fn (Builder $query) => $query->where('staff_id', $feed->staff_id),
            )
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Completed->value])
            ->where('starts_at', '>=', now()->subHours((int) config('calendar_sync.trailing_hours')))
            ->where('starts_at', '<=', now()->addDays((int) config('calendar_sync.window_days')))
            ->with(['customer', 'service', 'staff'])
            ->orderBy('starts_at')
            ->get();
    }

    private function calendarName(Tenant $tenant, CalendarFeedToken $feed): string
    {
        $who = $feed->scope === CalendarFeedToken::SCOPE_SALON
            ? 'All staff'
            : ($feed->staff?->name ?? 'All staff');

        return $tenant->name.' — '.$who;
    }

    private function contentMode(Tenant $tenant): string
    {
        $mode = (string) data_get($tenant->settings, 'calendar_feed_content_mode', IcalFeedBuilder::MODE_FULL);

        return in_array($mode, IcalFeedBuilder::modes(), true) ? $mode : IcalFeedBuilder::MODE_FULL;
    }

    private function recordPull(CalendarFeedToken $feed): void
    {
        try {
            CalendarFeedToken::query()
                ->withoutGlobalScopes()
                ->whereKey($feed->getKey())
                ->update(['last_pulled_at' => now()]);
        } catch (Throwable) {
        }
    }
}
