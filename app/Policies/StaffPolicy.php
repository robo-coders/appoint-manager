<?php

namespace App\Policies;

use App\Models\User;

class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function view(User $user, User $staff): bool
    {
        return $this->owns($user, $staff);
    }

    public function create(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function update(User $user, User $staff): bool
    {
        return $this->owns($user, $staff);
    }

    public function delete(User $user, User $staff): bool
    {
        return false;
    }

    private function owns(User $user, User $staff): bool
    {
        return $user->tenant_id !== null
            && $staff->tenant_id !== null
            && $user->tenant_id === $staff->tenant_id
            && $staff->tenant_id === current_tenant_id();
    }
}
