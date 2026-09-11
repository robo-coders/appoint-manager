<?php

namespace App\BetaSandbox;

use App\Models\Tenant;

final class BetaSandbox
{
    public static function enabled(?Tenant $tenant): bool
    {
        return $tenant !== null && $tenant->is_beta === true;
    }

    public static function guard(?Tenant $tenant): Tenant
    {
        abort_unless(self::enabled($tenant), 404);

        return $tenant;
    }
}
