<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\ContactVisibility;

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

    /**
     * May this person read the customer's phone number and email address?
     *
     * Seeing the record and reading the contact details are two different
     * permissions: a staff member who cannot ring somebody can still be shown
     * that they exist, what they booked and when. `Settings → People` sets the
     * toggle; `ContactVisibility` holds the rule, so the list screens — which
     * ask this forty times — can resolve it in one query rather than forty.
     */
    public function viewContact(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer)
            && ContactVisibility::for($user)->customer($customer);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer) && $user->isOwner();
    }
}
