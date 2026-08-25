<?php

namespace App\Policies;

use App\Models\AvailabilityRule;
use App\Models\User;

class AvailabilityRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function view(User $user, AvailabilityRule $availabilityRule): bool
    {
        return $this->owns($user, $availabilityRule->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function update(User $user, AvailabilityRule $availabilityRule): bool
    {
        return $this->owns($user, $availabilityRule->tenant_id);
    }

    public function delete(User $user, AvailabilityRule $availabilityRule): bool
    {
        return $this->owns($user, $availabilityRule->tenant_id);
    }

    private function owns(User $user, ?int $tenantId): bool
    {
        return $user->tenant_id !== null
            && $tenantId !== null
            && $user->tenant_id === $tenantId
            && $tenantId === current_tenant_id();
    }
}
