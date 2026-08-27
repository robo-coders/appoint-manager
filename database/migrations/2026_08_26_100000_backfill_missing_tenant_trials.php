<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give a trial to every tenant that was created without one.
 *
 * `Tenant::booted()` stops this happening again, but it only runs on new rows.
 * Anything already in a database — a tenant seeded by hand, one made in a
 * tinker one-liner, one imported — still has `subscription_status = 'trial'`
 * and `trial_ends_at = NULL`, and `hasAdminWriteAccess()` reads the date. Those
 * accounts are sitting behind "Admin is read-only until billing is up to date"
 * with no way to clear it short of paying.
 *
 * Only that exact state is touched. A tenant on `active` or `paused` is
 * supposed to have no trial date; one on `past_due` or `cancelled` has ended
 * its trial and must not be handed a fresh one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')
            ->whereNull('trial_ends_at')
            ->where('subscription_status', 'trial')
            ->update(['trial_ends_at' => now()->addDays((int) config('billing.trial_days'))]);
    }

    public function down(): void
    {
        // Irreversible on purpose: there is no record of which rows were NULL
        // before this ran, and clearing every trial date would lock out every
        // tenant that has legitimately been on one since.
    }
};
