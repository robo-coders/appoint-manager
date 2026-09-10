<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use App\Support\ContactVisibility;

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

    /**
     * May this person read the customer's contact details *on this booking*?
     *
     * The owner and anybody with `can_see_customer_contacts` always may. For
     * everybody else it is `staff_id`: your own appointment, your own customer's
     * number. A colleague's appointment is not, even for a customer you happen
     * to see elsewhere — the question this screen asks is about the appointment
     * in front of you.
     */
    public function viewContact(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking)
            && ContactVisibility::for($user)->booking($booking);
    }

    private function owns(User $user, ?int $tenantId): bool
    {
        return $user->tenant_id !== null
            && $tenantId !== null
            && $user->tenant_id === $tenantId
            && $tenantId === current_tenant_id();
    }
}
