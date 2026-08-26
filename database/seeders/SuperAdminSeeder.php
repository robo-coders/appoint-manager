<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The super admin account, for local development only.
 *
 * There is no other way to get into the console. The admin surface has its own
 * login and refuses anyone whose `is_super_admin` is false, registration only
 * ever creates tenant owners, and nothing in the app promotes a user — by
 * design, since "become a super admin" is not a feature a salon owner should be
 * one bug away from. So the first console account has to be seeded, and on a
 * real deployment it is made by hand.
 *
 * Guarded hard against running anywhere but local. A seeder that creates a
 * known-password god account is a back door if it ever executes in production,
 * and `--force` would happily run it there.
 */
class SuperAdminSeeder extends Seeder
{
    private const EMAIL = 'admin@gmail.com';

    private const PASSWORD = 'admin@1234';

    public function run(): void
    {
        /*
         * Not `app()->environment('local', 'testing')`. This throws rather than
         * returning quietly, because silently doing nothing is how you end up
         * believing an account exists when it does not.
         */
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'SuperAdminSeeder refuses to run in ['.app()->environment().']. '
                .'It creates an account with a known password and is for local development only. '
                .'Create production super admins by hand.'
            );
        }

        $existing = User::withoutGlobalScopes()->where('email', self::EMAIL)->first();

        if ($existing === null) {
            $this->create();

            return;
        }

        /*
         * The interesting case, and the one that actually happened here.
         *
         * `admin@gmail.com` was already taken — by a TENANT OWNER created
         * through the ordinary signup form. That account can never reach the
         * console: `AdminSessionController` authenticates it, sees
         * `is_super_admin` is false, and throws it straight back out with the
         * same "these credentials do not match" message a wrong password gets.
         * Which looks exactly like a forgotten password, and is why the console
         * appeared to be unreachable.
         *
         * A super admin must not also be a tenant user — it would carry a
         * tenant context into every scoped query in the console — so the
         * account is detached from its tenant rather than left in both roles.
         */
        if ($existing->tenant_id !== null) {
            $orphaned = Tenant::withoutGlobalScopes()->find($existing->tenant_id);

            $existing->forceFill([
                'tenant_id' => null,
                'is_super_admin' => true,
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
                'is_bookable' => false,
            ])->save();

            $this->command?->warn(
                'Converted the existing tenant owner '.self::EMAIL.' into a super admin.'
            );

            if ($orphaned !== null && $orphaned->users()->withoutGlobalScopes()->count() === 0) {
                $this->command?->warn(
                    "Tenant #{$orphaned->id} \"{$orphaned->name}\" now has no users. It was created by "
                    .'that signup and is almost certainly test data — delete it when you are sure.'
                );
            }

            return;
        }

        // Already a console account. Re-assert the password so the documented
        // credentials are always the working ones.
        $existing->forceFill([
            'is_super_admin' => true,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ])->save();

        $this->command?->info('Super admin '.self::EMAIL.' already existed — password reset to the documented one.');
    }

    private function create(): void
    {
        $user = new User;

        /*
         * forceFill because `tenant_id` is deliberately not fillable — mass
         * assignment is not allowed to move a user between tenants.
         *
         * `role` is a non-nullable column and the enum has only Owner and
         * Staff. Owner is the honest answer for an account that is nobody's
         * staff member; nothing reads it while `tenant_id` is null.
         */
        $user->forceFill([
            'tenant_id' => null,
            'name' => 'Super Admin',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'role' => UserRole::Owner,
            'is_super_admin' => true,
            'is_bookable' => false,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $this->command?->info('Created super admin '.self::EMAIL.'.');
    }
}
