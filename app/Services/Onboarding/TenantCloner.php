<?php

namespace App\Services\Onboarding;

use App\Enums\UserRole;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantCloner
{
    public function copy(Tenant $from, Tenant $to): void
    {
        DB::transaction(function () use ($from, $to) {
            $owner = User::withoutGlobalScopes()
                ->where('tenant_id', $to->id)
                ->where('role', UserRole::Owner)
                ->first();

            Service::withoutGlobalScopes()->where('tenant_id', $from->id)->get()->each(function (Service $service) use ($to, $owner) {
                $copy = $service->replicate();
                $copy->tenant_id = $to->id;
                $copy->save();

                if ($owner) {
                    $copy->staff()->syncWithoutDetaching([$owner->id]);
                }
            });

            AvailabilityRule::withoutGlobalScopes()
                ->where('tenant_id', $from->id)
                ->get()
                ->each(function (AvailabilityRule $rule) use ($to, $owner) {
                    if ($owner === null) {
                        return;
                    }

                    $copy = $rule->replicate();
                    $copy->tenant_id = $to->id;
                    $copy->user_id = $owner->id;
                    $copy->save();
                });

            $settings = $to->settings ?? [];
            $settings['booking'] = data_get($from->settings, 'booking', []);
            $to->forceFill(['settings' => $settings, 'type' => $from->type])->save();
        });
    }
}
