<?php

namespace App\Sandbox;

use App\BetaSandbox\BetaSandbox;
use App\BetaSandbox\SandboxMute;
use App\Enums\BookingStatus;
use App\Enums\PreferredTime;
use App\Enums\SlotOfferStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\SlotOffer;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\Booking\BookingService;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\TenantContext;

final class WaitlistSimulator
{
    public function __construct(
        private BookingService $bookings,
        private WaitlistOfferer $waitlist,
    ) {}

    /**
     * @return array{cancelled: int, offered: int}
     */
    public function freeSlot(Tenant $tenant): array
    {
        BetaSandbox::guard($tenant);

        $context = app(TenantContext::class);
        $previous = $context->tenant();
        $context->set($tenant);

        try {
            return SandboxMute::while(function () use ($tenant): array {
                $booking = $this->bookedWithWaitlist($tenant);
                $this->ensureWaiter($tenant, $booking);
                $this->bookings->cancel($booking, 'sandbox_waitlist', true);

                $offered = SlotOffer::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('staff_id', $booking->staff_id)
                    ->where('starts_at', $booking->starts_at)
                    ->where('status', SlotOfferStatus::Sent->value)
                    ->count();

                return ['cancelled' => 1, 'offered' => $offered];
            });
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    /**
     * @return array{expired: int, offered: int}
     */
    public function expireOffer(Tenant $tenant): array
    {
        BetaSandbox::guard($tenant);

        $offer = SlotOffer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', SlotOfferStatus::Sent->value)
            ->orderBy('expires_at')
            ->first();

        if ($offer === null) {
            throw SandboxRefusal::because('There is no waitlist offer to expire. Free up a slot first.');
        }

        $context = app(TenantContext::class);
        $previous = $context->tenant();
        $context->set($tenant);

        try {
            return SandboxMute::while(function () use ($tenant, $offer): array {
                SlotOffer::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($offer->id)
                    ->update(['expires_at' => now()->subSecond()]);

                $this->waitlist->expireAndContinue($tenant->id);

                $next = SlotOffer::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('staff_id', $offer->staff_id)
                    ->where('starts_at', $offer->starts_at)
                    ->where('status', SlotOfferStatus::Sent->value)
                    ->count();

                return ['expired' => 1, 'offered' => $next];
            });
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    private function bookedWithWaitlist(Tenant $tenant): Booking
    {
        $confirmed = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        foreach ($confirmed as $booking) {
            $waiting = WaitlistEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('service_id', $booking->service_id)
                ->where('is_active', true)
                ->where('customer_id', '!=', $booking->customer_id)
                ->exists();

            if ($waiting) {
                return $booking;
            }
        }

        $fallback = $confirmed->first()
            ?? Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', BookingStatus::Confirmed->value)
                ->orderByDesc('starts_at')
                ->first();

        if ($fallback === null) {
            throw SandboxRefusal::because('There is no booked slot to free. Load sample data first.');
        }

        return $fallback;
    }

    private function ensureWaiter(Tenant $tenant, Booking $booking): void
    {
        $existing = WaitlistEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('service_id', $booking->service_id)
            ->where('is_active', true)
            ->where('customer_id', '!=', $booking->customer_id)
            ->exists();

        if ($existing) {
            return;
        }

        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('id', '!=', $booking->customer_id)
            ->orderBy('id')
            ->first();

        if ($customer === null) {
            $customer = Customer::query()->create([
                'name' => 'Waitlist Neighbour',
                'email' => 'waitlist.'.$tenant->id.'@example.test',
                'phone' => '07700900999',
                'notes' => 'Sample data.',
            ]);
        }

        $subject = Subject::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->orderBy('id')
            ->first();

        WaitlistEntry::query()->create([
            'customer_id' => $customer->id,
            'subject_id' => $subject?->id,
            'service_id' => $booking->service_id,
            'preferred_days' => [],
            'preferred_times' => PreferredTime::Any,
            'notes' => 'Sample data.',
            'is_active' => true,
            'expires_at' => now()->addWeeks(2),
        ]);
    }
}
