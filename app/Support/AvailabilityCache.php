<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class AvailabilityCache
{
    public static function key(int $tenantId, int $serviceId, string $from, string $to, ?int $staffId = null): string
    {
        return sprintf(
            'availability:%d:%d:%s:%s:%s:%s',
            $tenantId,
            self::version($tenantId),
            $serviceId,
            $from,
            $to,
            $staffId ?? 'all',
        );
    }

    public static function version(int $tenantId): int
    {
        return (int) Cache::get(self::versionKey($tenantId), 1);
    }

    public static function bust(int $tenantId): void
    {
        $key = self::versionKey($tenantId);
        $current = (int) Cache::get($key, 1);
        Cache::forever($key, $current + 1);
    }

    private static function versionKey(int $tenantId): string
    {
        return "availability-version:{$tenantId}";
    }
}
