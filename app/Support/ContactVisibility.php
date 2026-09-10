<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;

/**
 * Who is allowed to read a customer's phone number and email address.
 *
 * The rule, in the order it is asked:
 *
 *   1. **The owner always can.** It is their customer list.
 *   2. **A staff member with `can_see_customer_contacts` always can.** That is
 *      what the toggle in Settings → People means, and it is what the onboarding
 *      step promised when it was set.
 *   3. **Otherwise, only for their own customers** — the people they personally
 *      have an appointment with. You cannot do the job without being able to
 *      ring the person whose dog is in front of you, and a permission that
 *      stopped you would be turned off within the week.
 *
 * **Why a class and not four calls to `Gate::allows`.** Rule 3 is a question
 * about a *set* — "which of these forty customers are mine" — and asking it per
 * row is forty queries on a list screen. This resolves it once per request into
 * an id set and answers from memory. The policies (`CustomerPolicy::viewContact`,
 * `BookingPolicy::viewContact`) are the single-record front door and defer to
 * this, so the two can never drift apart.
 *
 * A booking is a narrower question than a customer and gets its own method:
 * `staff_id` on the row answers it outright, with no set to build.
 */
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

    /**
     * Every customer this person personally has an appointment with.
     *
     * Built on first use rather than in the constructor, because the two
     * questions this class answers do not both need it: `booking()` is decided
     * by `staff_id` on the row in front of it, and a booking screen that
     * eagerly loaded this set would pay for a query it never reads. A list
     * screen asks `customer()` forty times and pays for it once.
     *
     * `distinct` on the id rather than loading bookings: the answer is a set
     * membership test and the rows themselves are never read.
     *
     * @return array<int, true>
     */
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

    /** Everything, with no per-record question to ask. */
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

    /**
     * A booking is answered by the row, not by the set.
     *
     * Deliberately `staff_id`, not "is this customer mine": the question a
     * booking screen asks is about *this appointment*, and a staff member
     * looking at a colleague's booking for a customer they also see elsewhere
     * has no reason to be given the number from here.
     */
    public function booking(Booking $booking): bool
    {
        return $this->unrestricted
            || ($this->userId !== null && $booking->staff_id === $this->userId);
    }
}
