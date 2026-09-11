<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class Loyalty
{
    public function enabled(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'loyalty.enabled', false);
    }

    public function activePackage(Tenant $tenant): ?LoyaltyPackage
    {
        if (! $this->enabled($tenant)) {
            return null;
        }

        return LoyaltyPackage::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    public function enrol(Tenant $tenant, Customer $customer): ?LoyaltyEnrolment
    {
        $package = $this->activePackage($tenant);

        if ($package === null) {
            return null;
        }

        $enrolment = $this->enrolmentFor($tenant, $customer);

        if ($enrolment !== null) {
            if (! $enrolment->isEarning()) {
                $enrolment->forceFill([
                    'loyalty_package_id' => $package->id,
                    'stamps_used' => 0,
                    'status' => LoyaltyCardStatus::Active,
                    'completed_at' => null,
                    'redeemed_at' => null,
                ])->save();
            }

            return $enrolment;
        }

        return LoyaltyEnrolment::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_id' => $customer->id],
            ['loyalty_package_id' => $package->id, 'stamps_used' => 0, 'cycles_completed' => 0],
        );
    }

    public function rewardDue(Tenant $tenant, Customer $customer): bool
    {
        if (! $this->enabled($tenant)) {
            return false;
        }

        $enrolment = $this->enrolmentFor($tenant, $customer);

        if ($enrolment === null || ! $enrolment->isEarning() || ! $enrolment->package->auto_apply_reward) {
            return false;
        }

        return $enrolment->rewardDue();
    }

    public function spendReward(Tenant $tenant, Customer $customer): void
    {
        $enrolment = $this->enrolmentFor($tenant, $customer);

        if ($enrolment === null || ! $enrolment->rewardDue()) {
            return;
        }

        LoyaltyEnrolment::withoutGlobalScopes()
            ->whereKey($enrolment->getKey())
            ->update([
                'stamps_used' => 0,
                'cycles_completed' => DB::raw('cycles_completed + 1'),
                'status' => LoyaltyCardStatus::Redeemed->value,
                'completed_at' => null,
                'redeemed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function refundReward(Tenant $tenant, Customer $customer): void
    {
        if (! $this->enabled($tenant)) {
            return;
        }

        $enrolment = $this->enrolmentFor($tenant, $customer);

        if ($enrolment === null || ! $enrolment->isEarning()) {
            return;
        }

        LoyaltyEnrolment::withoutGlobalScopes()
            ->whereKey($enrolment->getKey())
            ->update([
                'stamps_used' => (int) $enrolment->package->sessions_required,
                'cycles_completed' => DB::raw('GREATEST(cycles_completed - 1, 0)'),
                'status' => LoyaltyCardStatus::StampedOut->value,
                'completed_at' => now(),
                'redeemed_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function progressLine(Booking $booking): ?string
    {
        $tenant = $booking->tenant_id === null
            ? null
            : Tenant::query()->find($booking->tenant_id);

        if ($tenant === null || ! $this->enabled($tenant)) {
            return null;
        }

        $customer = Customer::withoutGlobalScopes()->find($booking->customer_id);
        $enrolment = $customer === null ? null : $this->enrolmentFor($tenant, $customer);

        if ($enrolment === null || ! $enrolment->isEarning()) {
            return null;
        }

        $required = (int) $enrolment->package->sessions_required;

        if ($booking->is_loyalty_reward) {
            return 'This one is free — '.$required.' stamps used.';
        }

        $after = min($required, $enrolment->stamps_used + 1);
        $remaining = $required - $after;

        if ($remaining <= 0) {
            return $after.' of '.$required.' stamps — the next one is free.';
        }

        return $after.' of '.$required.' stamps — '.$remaining
            .' more until your free session.';
    }

    public function enrolmentFor(Tenant $tenant, Customer $customer): ?LoyaltyEnrolment
    {
        return LoyaltyEnrolment::withoutGlobalScopes()
            ->with('package')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->first();
    }
}
