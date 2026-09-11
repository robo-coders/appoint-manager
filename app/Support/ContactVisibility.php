<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;

final class ContactVisibility
{
    /** @var array<int, true>|null Built on first use — see `ownCustomerIds()`. */
    private ?array $ownCustomerIds = null;

    private function __construct(
        private readonly bool $unrestricted,
        private readonly ?int $userId,
    ) {}

    public static function for(?User $user): self
    {
        if ($user === null) {
            return new self(false, null);
        }

        return new self(
            $user->is_super_admin || $user->isOwner() || $user->can_see_customer_contacts,
            $user->id,
        );
    }

    /** @return array<int, true> */
    private function ownCustomerIds(): array
    {
        if ($this->ownCustomerIds !== null) {
            return $this->ownCustomerIds;
        }

        if ($this->userId === null) {
            return $this->ownCustomerIds = [];
        }

        $ids = Booking::query()
            ->where('staff_id', $this->userId)
            ->whereNotNull('customer_id')
            ->distinct()
            ->pluck('customer_id')
            ->all();

        return $this->ownCustomerIds = array_fill_keys(array_map('intval', $ids), true);
    }

    public function unrestricted(): bool
    {
        return $this->unrestricted;
    }

    public function customer(Customer|int|null $customer): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        $id = $customer instanceof Customer ? $customer->id : $customer;

        return $id !== null && isset($this->ownCustomerIds()[(int) $id]);
    }

    public function booking(Booking $booking): bool
    {
        return $this->unrestricted
            || ($this->userId !== null && $booking->staff_id === $this->userId);
    }
}
