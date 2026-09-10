<?php

namespace App\Services\Booking;

use App\Enums\SetupReason;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;

final class BookingReadiness
{
    public static function isReady(Tenant $tenant): bool
    {
        return self::reasonFor($tenant) === null;
    }

    public static function reasonFor(Tenant $tenant): ?SetupReason
    {
        if (! self::hasBookableService($tenant)) {
            return SetupReason::NoService;
        }

        if (! self::hasStaffWithHours($tenant)) {
            return SetupReason::NoStaff;
        }

        return null;
    }

    public static function hasBookableService(Tenant $tenant): bool
    {
        return Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->exists();
    }

    public static function hasStaffWithHours(Tenant $tenant): bool
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->whereIn('id', AvailabilityRule::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->select('user_id'))
            ->exists();
    }

    public static function hasStaffForService(Tenant $tenant, Service $service): bool
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('is_bookable', true)
            ->whereHas('services', fn ($services) => $services
                ->withoutGlobalScopes()
                ->where('services.id', $service->id))
            ->exists();
    }
}
