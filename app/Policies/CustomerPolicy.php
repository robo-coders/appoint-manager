<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null && $user->tenant_id === current_tenant_id();
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->tenant_id !== null
            && $customer->tenant_id !== null
            && $user->tenant_id === $customer->tenant_id
            && $customer->tenant_id === current_tenant_id();
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer) && $user->isOwner();
    }
}
