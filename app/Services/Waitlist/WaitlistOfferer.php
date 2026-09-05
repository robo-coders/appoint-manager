<?php

namespace App\Services\Waitlist;

use App\Enums\PreferredTime;
use App\Enums\SlotOfferStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Notifications\Notifier;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WaitlistOfferer
{
    public function __construct(private Notifier $notifier) {}

    public function offerForBooking(Booking $booking): int
    {
        $tenant = $booking->tenant ?? Tenant::query()->findOrFail($booking->tenant_id);
        app(TenantContext::class)->set($tenant);
        $service = $booking->service ?? Service::withoutGlobalScopes()->findOrFail($booking->service_id);
        $staff = $booking->staff ?? User::withoutGlobalScopes()->findOrFail($booking->staff_id);

        return $this->offer(
            $tenant,
            $service,
            $staff,
            CarbonImmutable::parse($booking->starts_at)->utc(),
            CarbonImmutable::parse($booking->ends_at)->utc(),
        );
    }

    public function offer(
        Tenant $tenant,
        Service $service,
        User $staff,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): int {
        $batch = (int) data_get($tenant->settings, 'waitlist.offer_batch_size', config('booking.waitlist_offer_batch'));
        $ttl = (int) data_get($tenant->settings, 'waitlist.offer_ttl_minutes', config('booking.waitlist_offer_minutes'));
        app(TenantContext::class)->set($tenant);
        $matches = $this->rankedMatches($tenant, $service, $startsAt)
            ->reject(function (WaitlistEntry $entry) use ($startsAt) {
                return SlotOffer::withoutGlobalScopes()
                    ->where('waitlist_entry_id', $entry->id)
                    ->where('starts_at', $startsAt)
                    ->whereIn('status', [SlotOfferStatus::Sent->value, SlotOfferStatus::Claimed->value])
                    ->exists();
            })
            ->take($batch)
            ->values();

        $created = 0;

        foreach ($matches as $entry) {
            $offer = new SlotOffer;
            $offer->forceFill([
                'tenant_id' => $tenant->id,
                'waitlist_entry_id' => $entry->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'token' => (string) Str::uuid(),
                'status' => SlotOfferStatus::Sent,
                'expires_at' => now()->addMinutes($ttl),
            ]);
            $offer->save();
            $this->notifier->waitlistOffer($tenant, $entry->customer, book_url(null, 'offer/'.$offer->token));
            $created++;
        }

        return $created;
    }

    /**
     * @return Collection<int, WaitlistEntry>
     */
    public function rankedMatches(Tenant $tenant, Service $service, CarbonImmutable $startsAt): Collection
    {
        $local = $startsAt->timezone($tenant->timezone);
        $weekday = (int) $local->isoWeekday();
        $hour = (int) $local->hour;

        return WaitlistEntry::withoutGlobalScopes()
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->id)
            ->where('service_id', $service->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get()
            ->filter(function (WaitlistEntry $entry) use ($weekday, $hour) {
                $days = $entry->preferred_days ?? [];

                if ($days !== [] && ! in_array($weekday, array_map('intval', $days), true)) {
                    return false;
                }

                return match ($entry->preferred_times) {
                    PreferredTime::Any => true,
                    PreferredTime::Morning => $hour < 12,
                    PreferredTime::Afternoon => $hour >= 12,
                    default => true,
                };
            })
            ->sort(function (WaitlistEntry $a, WaitlistEntry $b) {
                $score = $this->fitScore($b) <=> $this->fitScore($a);

                if ($score !== 0) {
                    return $score;
                }

                $age = $a->created_at <=> $b->created_at;

                if ($age !== 0) {
                    return $age;
                }

                return $a->id <=> $b->id;
            })
            ->values();
    }

    /**
     * Retire the offers whose window has closed, and offer the slot on.
     *
     * `$tenantId` narrows the sweep to one salon. The scheduled command passes
     * nothing and sweeps the platform, which is what it has always done; a
     * caller that must not touch anybody else's rows — running one tenant's
     * automation early, on demand — passes an id and gets exactly that tenant.
     *
     * The filter is on the read, not on the write side, so nothing downstream
     * has to remember the restriction: an offer that is not in the result set is
     * never expired, never superseded, and never re-offered.
     */
    public function expireAndContinue(?int $tenantId = null): void
    {
        $expired = SlotOffer::withoutGlobalScopes()
            ->where('status', SlotOfferStatus::Sent->value)
            ->where('expires_at', '<=', now())
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();

        foreach ($expired->groupBy(fn (SlotOffer $offer) => $offer->staff_id.'|'.$offer->starts_at) as $group) {
            foreach ($group as $offer) {
                $offer->forceFill(['status' => SlotOfferStatus::Expired])->save();
            }

            $sample = $group->first();
            $claimed = SlotOffer::withoutGlobalScopes()
                ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
                ->where('staff_id', $sample->staff_id)
                ->where('starts_at', $sample->starts_at)
                ->where('status', SlotOfferStatus::Claimed->value)
                ->exists();

            if ($claimed) {
                continue;
            }

            $tenant = Tenant::query()->find($sample->tenant_id);
            $service = Service::withoutGlobalScopes()->find($sample->service_id);
            $staff = User::withoutGlobalScopes()->find($sample->staff_id);

            if ($tenant && $service && $staff) {
                $this->offer(
                    $tenant,
                    $service,
                    $staff,
                    CarbonImmutable::parse($sample->starts_at)->utc(),
                    CarbonImmutable::parse($sample->ends_at)->utc(),
                );
            }
        }
    }

    private function fitScore(WaitlistEntry $entry): int
    {
        $days = $entry->preferred_days ?? [];
        $score = $days === [] ? 0 : 1;

        return $score + match ($entry->preferred_times) {
            PreferredTime::Morning, PreferredTime::Afternoon => 2,
            default => 0,
        };
    }
}
