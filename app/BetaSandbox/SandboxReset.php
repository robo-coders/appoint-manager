<?php

namespace App\BetaSandbox;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * "Reset my shop" — empty the diary, keep the shop.
 *
 * This is **not** account deletion and the dialog in front of it says so in
 * those words. What goes is the lived-in data: customers, their pets, every
 * booking, the waitlist, slot offers, the send log, loyalty progress, the
 * rebooking ledger. What stays is everything the owner set up — the tenant row,
 * their login, staff, services, opening hours, time off, branding, loyalty
 * packages, the Stripe connection and the subscription — so that pressing this
 * returns them to a shop they can immediately use, not to a signup form.
 *
 * **One transaction.** A half-wiped tenant is the worst outcome available here:
 * customers gone and bookings left behind is a diary full of rows pointing at
 * nothing, and the owner has no way to finish the job. Either every table in
 * `SandboxTables::transactional()` empties or none of them do.
 *
 * **Query builder, not Eloquent.** No model events fire — deleting a booking
 * must not stamp a loyalty card, and deleting a customer must not go looking
 * for subjects to unblock — and no global scope is involved, so the
 * `where('tenant_id', …)` on every single statement is visible in this file
 * rather than inherited from an ambient context that may not be set.
 */
final class SandboxReset
{
    /**
     * @return array<string, int> Rows removed, per table. The screen reports it.
     */
    public function run(Tenant $tenant): array
    {
        BetaSandbox::guard($tenant);

        return DB::transaction(function () use ($tenant): array {
            $removed = [];

            foreach (SandboxTables::transactional() as $table) {
                $removed[$table] = DB::table($table)->where('tenant_id', $tenant->id)->delete();
            }

            return $removed;
        });
    }
}
