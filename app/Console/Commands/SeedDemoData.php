<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `db:seed --class=` cannot take an argument, and this seeder needs one: it
 * fills a tenant you name rather than creating one, and pointing it at the
 * wrong salon would delete that salon's rows. So the tenant is a required
 * argument on a real command instead of an environment variable somebody
 * forgets to set.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed
        {tenant : Tenant id or slug — the ONLY tenant this touches}
        {--plan=active : Billing state to leave the tenant on: active, trial or expired}
        {--plan-only : Set the billing state and change nothing else}';

    protected $description = 'Fill one local tenant with realistic demo data (local only)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('demo:seed is local only. It deletes rows and writes fake clients.');

            return self::FAILURE;
        }

        $plan = (string) $this->option('plan');

        if (! in_array($plan, ['active', 'trial', 'expired'], true)) {
            $this->error("--plan must be active, trial or expired. Got [{$plan}].");

            return self::FAILURE;
        }

        try {
            $tenant = DemoDataSeeder::resolveTenant((string) $this->argument('tenant'));
        } catch (RuntimeException $e) {
            // A mistyped slug is a normal thing to do, not an exceptional one.
            $this->error($e->getMessage());

            $this->line('');
            $this->line('Tenants on this machine:');
            foreach (Tenant::query()->withoutGlobalScopes()->orderBy('id')->get() as $t) {
                $this->line(sprintf('  %-4s %-28s %s', $t->id, $t->slug, $t->name));
            }

            return self::FAILURE;
        }

        if (! $this->option('plan-only')) {
            $this->warn("This deletes existing demo rows for \"{$tenant->name}\" (#{$tenant->id}) and no other tenant.");

            $seeder = new DemoDataSeeder;
            $seeder->setCommand($this);
            $seeder->forTenant($tenant);
        }

        DemoDataSeeder::billing($tenant, $plan);

        $this->info(match ($plan) {
            'active' => 'Billing: active monthly plan. The diary is writable and there is no banner.',
            'trial' => 'Billing: 14 days of trial left. The diary is writable and the trial banner shows.',
            'expired' => 'Billing: past due and out of the dunning window. The diary is READ ONLY — this is the state that shows "Admin is read-only until billing is up to date".',
        });

        return self::SUCCESS;
    }
}
