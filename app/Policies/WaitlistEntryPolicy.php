<?php

namespace App\Policies;

use App\Models\User;

class WaitlistEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }
}
