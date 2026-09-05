<?php

namespace App\Services\Loyalty;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Loyalty packages, v1: a count of sessions, and the next one free.
 *
 * Everything the feature does is one of four questions, and they are all here so
 * that the booking flow it hangs off has to learn as little as possible:
 *
 *   - `enabled()` — is this switched on for this tenant at all
 *   - `enrol()` — the automatic enrolment, on a customer's next booking
 *   - `rewardDue()` — is this customer's next appointment the free one
 *   - `stamp()` — a completed appointment, counted
 *
 * **Off by default, and off means nothing happens.** The flag lives in
 * `tenants.settings['loyalty']['enabled']`, beside `notifications.sms_enabled`
 * and the booking settings, so switching it on needs no migration. Every method
 * below returns early when it is off, and nothing reads a loyalty row anywhere
 * else in the codebase — so a tenant that has never touched the setting has the
 * feature not merely hidden but absent.
 *
 * **The reward is spent at booking, and the stamp is earned at completion.**
 * Those are deliberately different moments. A stamp is a session that actually
 * happened, so it cannot be earned by booking one and not turning up; the
 * reward has to be applied at booking, because that is when the price and the
 * deposit are decided and the point of the feature is that the free one is free
 * before the customer is asked for a card.
 *
 * **A reward booking earns no stamp.** `bookings.is_loyalty_reward` is what
 * makes that possible: without it a £0 booking is indistinguishable from a free
 * service, completing it would earn a stamp, and the reward would pay for
 * itself.
 */
final class Loyalty
{
    public function enabled(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'loyalty.enabled', false);
    }

    /**
     * The package a tenant is currently collecting towards, if any.
     *
     * v1 has one. `is_active` plus "the newest" rather than `sole()` because a
     * second row is a thing a later version adds and this should degrade to the
     * current one rather than throw.
     */
    public function activePackage(Tenant $tenant): ?LoyaltyPackage
    {
        if (! $this->enabled($tenant)) {
            return null;
        }

        /*
         * `withoutGlobalScopes()` with an explicit `tenant_id`, here and in
         * every read below. `TenantScope` fails closed — no context means no
         * rows, everywhere, including queue workers and artisan commands — and
         * this service is called from a model hook and from the notifier, where
         * there may be none. The tenant is an argument rather than an ambient
         * fact, which is the form AUDIT C9 asks for: code that spans contexts
         * says which one it means.
         */
        return LoyaltyPackage::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    /**
     * Automatic enrolment, on the customer's next booking.
     *
     * The alternative was the owner enrolling people by hand, which is a screen,
     * a button and a decision per customer for a feature whose whole promise is
     * that it runs itself. So the first booking a customer makes after the
     * setting goes on is the one that enrols them, and that booking counts.
     *
     * `firstOrCreate` on the unique index, so two bookings arriving together
     * cannot make two enrolments — the second gets a duplicate-key error and
     * reads the row the first wrote.
     */
    public function enrol(Tenant $tenant, Customer $customer): ?LoyaltyEnrolment
    {
        $package = $this->activePackage($tenant);

        if ($package === null) {
            return null;
        }

        $enrolment = $this->enrolmentFor($tenant, $customer);

        if ($enrolment !== null) {
            /*
             * Already enrolled, but on a package that has since been switched
             * off or deleted. v1 allows one enrolment per customer, so moving
             * them onto the current package is the only way they ever get onto a
             * replacement — and it keeps `cycles_completed`, because those
             * sessions did happen.
             */
            if (! $enrolment->isEarning()) {
                $enrolment->forceFill(['loyalty_package_id' => $package->id, 'stamps_used' => 0])->save();
            }

            return $enrolment;
        }

        return LoyaltyEnrolment::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'customer_id' => $customer->id],
            ['loyalty_package_id' => $package->id, 'stamps_used' => 0, 'cycles_completed' => 0],
        );
    }

    /** Is this customer's next appointment the free one? */
    public function rewardDue(Tenant $tenant, Customer $customer): bool
    {
        if (! $this->enabled($tenant)) {
            return false;
        }

        return $this->enrolmentFor($tenant, $customer)?->rewardDue() ?? false;
    }

    /**
     * Spend the reward: reset the cycle and count it.
     *
     * Called immediately after a booking has been created and marked
     * `is_loyalty_reward`, so the stamps that paid for it cannot also pay for
     * the one after. The reset happens here rather than when the free session is
     * *completed* because the reward has already been given — the price is zero
     * and no deposit was taken.
     *
     * Cancelling the free one gives the stamps back: see `refundReward()`.
     */
    public function spendReward(Tenant $tenant, Customer $customer): void
    {
        $enrolment = $this->enrolmentFor($tenant, $customer);

        if ($enrolment === null || ! $enrolment->rewardDue()) {
            return;
        }

        /*
         * `withoutGlobalScopes()`, and an update rather than a save. The scope
         * is dropped because this runs from paths with no tenant context —
         * `TenantScope` fails closed and would match nothing — and the row is
         * already known to belong to `$tenant`, because that is how it was
         * found. `cycles_completed + 1` is done in SQL so two writes cannot both
         * read the same value and lose one.
         */
        LoyaltyEnrolment::withoutGlobalScopes()
            ->whereKey($enrolment->getKey())
            ->update([
                'stamps_used' => 0,
                'cycles_completed' => DB::raw('cycles_completed + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Put the stamps back after a reward booking is cancelled.
     *
     * `spendReward()` clears the card the moment the free appointment is
     * created, which is the right moment — the price is already zero and no
     * deposit was asked for. But it left cancellation with nothing to say: the
     * customer had five stamps, took the free session, the session was called
     * off, and their card was empty. They had paid for a reward they never
     * received, which is not what a salon does with a paper card when it crosses
     * the stamps off and then closes for the day.
     *
     * So this is `spendReward()` backwards: the card goes back to full, and the
     * cycle it counted is uncounted. Their next appointment is the free one
     * again.
     *
     * Deliberately not called for a no-show. Missing the free appointment is
     * still taking it — the slot was held and nobody else could have it — and
     * that is the same rule the deposit rules already apply.
     */
    public function refundReward(Tenant $tenant, Customer $customer): void
    {
        if (! $this->enabled($tenant)) {
            return;
        }

        $enrolment = $this->enrolmentFor($tenant, $customer);

        /*
         * `isEarning()` because a package that has since been switched off or
         * deleted has no `sessions_required` to restore the card to, and the
         * enrolment is a record of past progress rather than a live one.
         */
        if ($enrolment === null || ! $enrolment->isEarning()) {
            return;
        }

        /*
         * In SQL, and unscoped, for the reasons `spendReward()` sets out.
         * `GREATEST` because a reward booking made before the tenant's current
         * package existed can be cancelled after it, and a negative count of
         * completed cycles would be worse than a slightly generous zero.
         */
        LoyaltyEnrolment::withoutGlobalScopes()
            ->whereKey($enrolment->getKey())
            ->update([
                'stamps_used' => (int) $enrolment->package->sessions_required,
                'cycles_completed' => DB::raw('GREATEST(cycles_completed - 1, 0)'),
                'updated_at' => now(),
            ]);
    }

    /**
     * One completed appointment, counted.
     *
     * Called from `Booking`'s `updated` hook when a booking's status becomes
     * `completed`, rather than from a controller, for the reason
     * `Customer::booted()` gives about the same shape of problem: a status a
     * seeder, an import, a support script or tinker can also set is a status
     * whose consequence must not live down one route.
     *
     * Not incremented past the package's own count. Two sessions completed after
     * the stamps were already full would otherwise read "7 of 5" on the customer
     * screen and hand out one free session for two earned — the paper-card
     * behaviour is that the card is full and the next one is free, once.
     */
    public function stamp(Booking $booking): void
    {
        $tenant = $booking->tenant_id === null
            ? null
            : Tenant::query()->find($booking->tenant_id);

        if ($tenant === null || ! $this->enabled($tenant) || $booking->is_loyalty_reward) {
            return;
        }

        $customer = Customer::withoutGlobalScopes()->find($booking->customer_id);

        if ($customer === null) {
            return;
        }

        $enrolment = $this->enrolmentFor($tenant, $customer);

        if ($enrolment === null || ! $enrolment->isEarning()) {
            return;
        }

        $required = (int) $enrolment->package->sessions_required;

        if ($enrolment->stamps_used >= $required) {
            return;
        }

        // In SQL, and unscoped, for the reasons `spendReward()` sets out.
        LoyaltyEnrolment::withoutGlobalScopes()
            ->whereKey($enrolment->getKey())
            ->update([
                'stamps_used' => DB::raw('stamps_used + 1'),
                'updated_at' => now(),
            ]);
    }

    /**
     * The one line a confirmation message says about the stamps, or null when
     * this tenant, this customer or this booking has nothing to say.
     *
     * Composed here rather than in `Notifier` so the wording is in one place and
     * the customer screen and the message cannot disagree about the count. Kept
     * short on purpose: it is appended to an SMS that already has a date and a
     * link in it, and `SmsSegments::fit` will shorten the salon's name to make
     * room for it — see `Notifier::fitSms`.
     */
    public function progressLine(Booking $booking): ?string
    {
        $tenant = $booking->tenant_id === null
            ? null
            : Tenant::query()->find($booking->tenant_id);

        if ($tenant === null || ! $this->enabled($tenant)) {
            return null;
        }

        $customer = Customer::withoutGlobalScopes()->find($booking->customer_id);
        $enrolment = $customer === null ? null : $this->enrolmentFor($tenant, $customer);

        if ($enrolment === null || ! $enrolment->isEarning()) {
            return null;
        }

        $required = (int) $enrolment->package->sessions_required;

        if ($booking->is_loyalty_reward) {
            return 'This one is free — '.$required.' stamps used.';
        }

        /*
         * The count on the message is the count *after* this appointment, not
         * before it. A confirmation that says "3 of 5" when the appointment just
         * booked is the fourth is a message the customer has to do arithmetic on
         * — and the arithmetic is the only thing they wanted from it.
         */
        $after = min($required, $enrolment->stamps_used + 1);
        $remaining = $required - $after;

        if ($remaining <= 0) {
            return $after.' of '.$required.' stamps — the next one is free.';
        }

        return $after.' of '.$required.' stamps — '.$remaining
            .' more until your free session.';
    }

    private function enrolmentFor(Tenant $tenant, Customer $customer): ?LoyaltyEnrolment
    {
        return LoyaltyEnrolment::withoutGlobalScopes()
            ->with('package')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->first();
    }
}
