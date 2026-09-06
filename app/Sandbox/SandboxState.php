<?php

namespace App\Sandbox;

use App\Models\Tenant;

final class SandboxState
{
    /**
     * @return array<string, mixed>
     */
    public static function get(Tenant $tenant): array
    {
        return is_array($tenant->sandbox_state) ? $tenant->sandbox_state : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public static function put(Tenant $tenant, array $patch): array
    {
        $state = array_merge(self::get($tenant), $patch);
        $tenant->forceFill(['sandbox_state' => $state])->save();

        return $state;
    }

    public static function remember(Tenant $tenant, string $label): void
    {
        self::put($tenant, [
            'last_action' => [
                'label' => $label,
                'at' => now()->toIso8601String(),
            ],
        ]);
    }

    public static function flaky(Tenant $tenant): bool
    {
        return (bool) (self::get($tenant)['flaky_network'] ?? false);
    }
}
