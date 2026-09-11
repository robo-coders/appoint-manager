<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    private const EMAIL = 'admin@gmail.com';

    private const PASSWORD = 'admin@1234';

    public function run(): void
    {
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
