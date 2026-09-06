<?php

namespace App\Sandbox;

use App\BetaSandbox\BetaSandbox;
use App\Models\Tenant;
use Illuminate\Http\Request;

final class ActingTenant
{
    public static function from(?Request $request = null): Tenant
    {
        $tenant = BetaSandbox::guard(current_tenant());

        if ($request === null) {
            return $tenant;
        }

        foreach (['tenant_id', 'tenant', 'tenant_slug'] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $value = (string) $request->input($field);

            abort_unless(
                $value === (string) $tenant->id || $value === (string) $tenant->slug,
                403,
                'A sandbox action can only be run on your own shop.',
            );
        }

        return $tenant;
    }
}
