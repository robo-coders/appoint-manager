<?php

namespace App\Sandbox;

use App\BetaSandbox\BetaSandbox;
use App\BetaSandbox\SandboxMute;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

final class NoShowSimulator
{
    public function __construct(private BookingService $bookings) {}

    /**
     * @return list<array{id: int, label: string}>
     */
    public static function candidates(Tenant $tenant): array
    {
        $tz = $tenant->timezone;

        return Booking::withoutGlobalScopes()
            ->with([
                'customer' => fn ($query) => $query->withoutGlobalScopes(),
                'service' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereBetween('starts_at', [now()->subDays(7), now()->addDays(14)])
            ->orderBy('starts_at')
            ->limit(24)
            ->get()
            ->map(function (Booking $booking) use ($tz): array {
                $when = CarbonImmutable::parse($booking->starts_at)->timezone($tz)->format('D j M H:i');
                $who = $booking->customer?->name ?? 'A customer';
                $service = $booking->service?->name ?? 'Appointment';

                return [
                    'id' => (int) $booking->id,
                    'label' => $who.' · '.$service.' · '.$when,
                ];
            })
            ->all();
    }

    public function mark(Tenant $tenant, ?int $bookingId, ?User $actor): Booking
    {
        BetaSandbox::guard($tenant);

        $booking = $this->pick($tenant, $bookingId);

        $context = app(TenantContext::class);
        $previous = $context->tenant();
        $context->set($tenant);

        $frozen = CarbonImmutable::hasTestNow() ? CarbonImmutable::now() : null;
        $starts = CarbonImmutable::parse($booking->starts_at);

        if ($starts->isFuture()) {
            CarbonImmutable::setTestNow($starts);
        }

        try {
            return SandboxMute::while(fn (): Booking => $this->bookings->markNoShow($booking, $actor));
        } finally {
            CarbonImmutable::setTestNow($frozen);
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    private function pick(Tenant $tenant, ?int $bookingId): Booking
    {
        if ($bookingId !== null) {
            $booking = Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereKey($bookingId)
                ->first();

            if ($booking === null) {
                throw SandboxRefusal::because('That appointment is not in this shop.');
            }

            return $booking;
        }

        $upcoming = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        if ($upcoming !== null) {
            return $upcoming;
        }

        $recent = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->orderByDesc('starts_at')
            ->first();

        if ($recent === null) {
            throw SandboxRefusal::because('There is no confirmed appointment to mark as a no-show. Load sample data first.');
        }

        return $recent;
    }
}
