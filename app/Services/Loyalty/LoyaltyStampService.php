<?php

namespace App\Services\Loyalty;

use App\Enums\LoyaltyCardStatus;
use App\Enums\LoyaltyStampMethod;
use App\Exceptions\LoyaltyCardFullException;
use App\Exceptions\ManualStampRequiresNoteException;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\LoyaltyStamp;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LoyaltyStampService
{
    public function __construct(private Loyalty $loyalty) {}

    public function stampAutomatically(Booking $booking): ?LoyaltyStamp
    {
        $tenant = $booking->tenant_id === null
            ? null
            : Tenant::query()->find($booking->tenant_id);

        if ($tenant === null || $booking->is_loyalty_reward) {
            return null;
        }

        $package = $this->loyalty->activePackage($tenant);

        if ($package === null || ! $package->auto_stamp) {
            return null;
        }

        if (! $package->coversService($booking->service_id)) {
            return null;
        }

        if ($this->stampExistsForBooking($tenant, $booking)) {
            return null;
        }

        $customer = Customer::withoutGlobalScopes()->find($booking->customer_id);

        if ($customer === null) {
            return null;
        }

        $enrolment = $this->loyalty->enrolmentFor($tenant, $customer);

        if ($enrolment === null) {
            if (! $package->auto_enrol) {
                return null;
            }

            $enrolment = $this->loyalty->enrol($tenant, $customer);
        }

        if ($enrolment === null || (int) $enrolment->loyalty_package_id !== (int) $package->id) {
            return null;
        }

        return $this->recordStamp(
            $tenant,
            $enrolment,
            $package,
            $booking,
            LoyaltyStampMethod::Automatic,
            null,
            $this->visitDateFor($booking),
            null,
        );
    }

    public function stampManually(
        LoyaltyEnrolment $card,
        ?Booking $booking,
        User $staff,
        ?string $note,
    ): LoyaltyStamp {
        if ($booking === null && blank($note)) {
            throw ManualStampRequiresNoteException::make();
        }

        $tenant = Tenant::query()->findOrFail($card->tenant_id);
        $package = $this->loyalty->activePackage($tenant);

        if ($package === null || (int) $card->loyalty_package_id !== (int) $package->id) {
            throw LoyaltyCardFullException::notCollecting();
        }

        if ($booking !== null && $this->stampExistsForBooking($tenant, $booking)) {
            throw LoyaltyCardFullException::alreadyStamped();
        }

        $stamp = $this->recordStamp(
            $tenant,
            $card,
            $package,
            $booking,
            LoyaltyStampMethod::Manual,
            $staff->id,
            $booking === null ? CarbonImmutable::now()->toDateString() : $this->visitDateFor($booking),
            $note,
        );

        if ($stamp === null) {
            throw LoyaltyCardFullException::notCollecting();
        }

        return $stamp;
    }

    private function recordStamp(
        Tenant $tenant,
        LoyaltyEnrolment $enrolment,
        LoyaltyPackage $package,
        ?Booking $booking,
        LoyaltyStampMethod $method,
        ?int $stampedBy,
        string $visitDate,
        ?string $note,
    ): ?LoyaltyStamp {
        return DB::transaction(function () use (
            $tenant, $enrolment, $package, $booking, $method, $stampedBy, $visitDate, $note
        ): ?LoyaltyStamp {
            $card = LoyaltyEnrolment::withoutGlobalScopes()
                ->whereKey($enrolment->getKey())
                ->lockForUpdate()
                ->first();

            if ($card === null) {
                return null;
            }

            if ($booking !== null && $this->stampExistsForBooking($tenant, $booking)) {
                return null;
            }

            $required = (int) $package->sessions_required;
            $used = $card->status === LoyaltyCardStatus::Redeemed ? 0 : (int) $card->stamps_used;

            if ($used >= $required) {
                return null;
            }

            $stamp = LoyaltyStamp::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'loyalty_enrolment_id' => $card->getKey(),
                'booking_id' => $booking?->id,
                'method' => $method,
                'stamped_by' => $stampedBy,
                'visit_date' => $visitDate,
                'note' => $note,
            ]);

            $filled = $used + 1;
            $complete = $filled >= $required;

            LoyaltyEnrolment::withoutGlobalScopes()
                ->whereKey($card->getKey())
                ->update([
                    'stamps_used' => $filled,
                    'status' => $complete ? LoyaltyCardStatus::StampedOut->value : LoyaltyCardStatus::Active->value,
                    'completed_at' => $complete ? now() : null,
                    'redeemed_at' => null,
                    'updated_at' => now(),
                ]);

            return $stamp;
        });
    }

    private function stampExistsForBooking(Tenant $tenant, Booking $booking): bool
    {
        return LoyaltyStamp::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('booking_id', $booking->id)
            ->exists();
    }

    private function visitDateFor(Booking $booking): string
    {
        return CarbonImmutable::parse($booking->starts_at)->toDateString();
    }
}
