<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use RuntimeException;

class SeedDemoData extends Command
{
    protected $signature = 'demo:seed
        {tenant : Tenant id or slug — the ONLY tenant this touches}
        {--plan=active : Billing state to leave the tenant on: active, trial or expired}
        {--plan-only : Set the billing state and change nothing else}
        {--no-deposits : Leave the tenant with no Stripe account, so the booking page says "pay on the day"}
        {--stripe-account= : A real Stripe test-mode connected account id. Required unless --no-deposits; see DEPLOY.md}';

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
            $this->error($e->getMessage());

            $this->line('');
            $this->line('Tenants on this machine:');
            foreach (Tenant::query()->withoutGlobalScopes()->orderBy('id')->get() as $t) {
                $this->line(sprintf('  %-4s %-28s %s', $t->id, $t->slug, $t->name));
            }

            return self::FAILURE;
        }

        $deposits = ! $this->option('no-deposits');
        $account = (string) $this->option('stripe-account');

        if ($account !== '' && ! str_starts_with($account, 'acct_')) {
            $this->error("--stripe-account must be a Stripe connected account id (acct_…). Got [{$account}].");

            return self::FAILURE;
        }

        if ($account !== '' && ! $deposits) {
            $this->error('--stripe-account and --no-deposits contradict each other. Pass one.');

            return self::FAILURE;
        }

        $planOnly = (bool) $this->option('plan-only');

        if (! $planOnly && $deposits && ! $this->depositsCanComplete($account)) {
            return self::FAILURE;
        }

        if (! $planOnly) {
            $this->warn("This deletes existing demo rows for \"{$tenant->name}\" (#{$tenant->id}) and no other tenant.");

            $seeder = new DemoDataSeeder;
            $seeder->setCommand($this);
            $seeder->forTenant($tenant, $deposits);
        }

        DemoDataSeeder::billing($tenant, $plan);

        if (! $planOnly) {
            DemoDataSeeder::deposits($tenant, $deposits, $account ?: null);
        }

        $this->info(match ($plan) {
            'active' => 'Billing: active monthly plan. The diary is writable and there is no banner.',
            'trial' => 'Billing: 14 days of trial left. The diary is writable and the trial banner shows.',
            'expired' => 'Billing: past due and out of the dunning window. The diary is READ ONLY — this is the state that shows "Admin is read-only until billing is up to date".',
        });

        if ($planOnly) {
            $this->info('Deposits: untouched. --plan-only changed the billing state and nothing else.');
        } elseif (! $deposits) {
            $this->info('Deposits: off. No Stripe account, so the booking page says "pay on the day".');
        } else {
            $this->info("Deposits: on, against {$account}. Test keys are in .env, so the whole deposit path completes — use card 4242 4242 4242 4242.");
        }

        return self::SUCCESS;
    }

    private function depositsCanComplete(string $account): bool
    {
        $missing = [];

        foreach ([
            'STRIPE_KEY' => 'services.stripe.key',
            'STRIPE_SECRET' => 'services.stripe.secret',
            'STRIPE_WEBHOOK_SECRET' => 'services.stripe.webhook_secret',
        ] as $variable => $key) {
            if (blank(config($key))) {
                $missing[] = $variable;
            }
        }

        if ($missing === [] && $account !== '') {
            return true;
        }

        $this->error('demo:seed cannot set up deposits, and will not pretend to.');
        $this->line('');

        if ($missing !== []) {
            $this->line('  Missing from .env: '.implode(', ', $missing));
            $this->line('    Stripe test keys, from https://dashboard.stripe.com/test/apikeys:');
            $this->line('      STRIPE_KEY=pk_test_…      publishable — the card form on the booking page uses it');
            $this->line('      STRIPE_SECRET=sk_test_…   secret — the PaymentIntent is created with it');
            $this->line('    And a webhook signing secret. Locally that is the Stripe CLI:');
            $this->line('      stripe listen --forward-to '.rtrim((string) config('app.url'), '/').'/stripe/webhook');
            $this->line('      STRIPE_WEBHOOK_SECRET=whsec_…   printed by the line above');
            $this->line('');
        }

        if ($account === '') {
            $this->line('  Missing: --stripe-account=acct_…');
            $this->line('    A test-mode connected account. Create one, once, with the keys above in place:');
            $this->line('      php artisan tinker --execute="echo (new Stripe\\StripeClient(config(\'services.stripe.secret\')))->accounts->create([\'type\' => \'express\', \'country\' => \'GB\', \'email\' => \'demo@example.com\'])->id;"');
            $this->line('    A new Express account cannot take charges until its onboarding is finished, and an');
            $this->line('    account that cannot take charges cannot take a deposit. DEPLOY.md, "Demo deposits');
            $this->line('    on test keys", has the account-link snippet and the test values the form wants.');
            $this->line('');
        }

        $this->line('  Or run it without deposits, which is what the e2e suite does:');
        $this->line('      php artisan demo:seed '.$this->argument('tenant').' --no-deposits');
        $this->line('    The booking page then says "pay on the day" and no Stripe call is made.');

        return false;
    }
}
