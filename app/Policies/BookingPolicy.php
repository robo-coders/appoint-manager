<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function update(User $user, Booking $booking): bool
    {
        return $this->owns($user, $booking->tenant_id);
    }

    private function owns(User $user, ?int $tenantId): bool
    {
        return $user->tenant_id !== null
            && $tenantId !== null
            && $user->tenant_id === $tenantId
            && $tenantId === current_tenant_id();
    }
}
