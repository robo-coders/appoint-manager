<?php

namespace App\Policies;

use App\Models\TimeOff;
use App\Models\User;

class TimeOffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function view(User $user, TimeOff $timeOff): bool
    {
        return $this->owns($user, $timeOff->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function update(User $user, TimeOff $timeOff): bool
    {
        return $this->owns($user, $timeOff->tenant_id);
    }

    public function delete(User $user, TimeOff $timeOff): bool
    {
        return $this->owns($user, $timeOff->tenant_id);
    }

    private function owns(User $user, ?int $tenantId): bool
    {
        return $user->tenant_id !== null
            && $tenantId !== null
            && $user->tenant_id === $tenantId
            && $tenantId === current_tenant_id();
    }
}
