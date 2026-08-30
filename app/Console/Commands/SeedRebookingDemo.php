<?php

namespace App\Console\Commands;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rebooking\RebookMessenger;
use App\Services\Sms\SmsConsent;
use App\Support\PhoneNumber;
use App\Support\SendWindow;
use App\Support\TenantContext;
use App\Support\TenantSlug;
use App\Support\VerticalInterval;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * A salon whose overdue list is worth looking at, and one phone number that is
 * yours.
 *
 * `demo:seed` fills a tenant you already have and produces bookings in the
 * future, because that is what a diary demo needs. Nothing in it is *overdue*,
 * so the whole rebooking surface seeded empty and could not be looked at, let
 * alone tested against a real handset.
 *
 * This builds the other half: a client base with a history, deliberately spread
 * so the list has variety — a few not due, a few just due, a few badly overdue —
 * and one subject whose number comes from `REBOOKING_DEMO_PHONE` so a real text
 * can arrive on a real phone.
 *
 * Idempotent, and that matters more here than usual: the command exists to be
 * run repeatedly while trying something out. Every row is keyed on something
 * deterministic (the tenant slug, a client's email, a subject's name, a visit's
 * exact start time), so a second run updates rather than doubles.
 */
class SeedRebookingDemo extends Command
{
    protected $signature = 'demo:rebooking
        {--slug=rebooking-demo : Tenant slug to create or refill}
        {--phone= : Your mobile in any UK format. Falls back to REBOOKING_DEMO_PHONE}
        {--enable-sending : Turn automatic messages on, skipping the dry-run gate}';

    protected $description = 'Seed a salon with an overdue list worth looking at (local only)';

    /**
     * The client base, written out rather than generated.
     *
     * `after` is days since the last visit. Whether a subject is overdue is
     * that minus the service's interval, so the fourth column is what produces
     * the variety and it is readable at a glance:
     *
     *   service 0  Full groom — small dog    42 days
     *   service 1  Full groom — medium dog   42 days
     *   service 2  Bath and blow dry         28 days
     *   service 3  Nail clip                 21 days
     *
     * @var list<array{0: string, 1: string, 2: int, 3: int, 4: string}>
     */
    private const CLIENTS = [
        // Not due yet — the list must not look like everybody is overdue.
        ['Barney', 'Alan Reid', 1, 14, 'Cockapoo'],
        ['Pippin', 'Sara Doyle', 0, 7, 'Miniature schnauzer'],
        ['Ziggy', 'Tom Ellis', 2, 10, 'Whippet'],
        ['Nell', 'Kate Ward', 3, 5, 'Border terrier'],

        // Just due — one to five days over.
        ['Bella', 'Emma Shaw', 1, 43, 'Cavapoo'],
        ['Rufus', 'Chris Vaz', 1, 44, 'Golden retriever'],
        ['Marlow', 'Ada Bright', 0, 45, 'Bichon frise'],
        ['Otto', 'Rhys Owen', 2, 29, 'Hungarian vizsla'],
        ['Suki', 'Nina Patel', 2, 32, 'Shih tzu'],
        ['Dougal', 'Ian Frost', 3, 23, 'Wire fox terrier'],

        // Two to four weeks over — the middle of the list.
        ['Hattie', 'Ruth Kane', 1, 56, 'Springer spaniel'],
        ['Wilf', 'Dan Cole', 1, 63, 'Labradoodle'],
        ['Coco', 'Liz Munro', 0, 70, 'Toy poodle'],
        ['Bruno', 'Paul Nash', 2, 50, 'Weimaraner'],
        ['Maisie', 'Jo Sterling', 3, 41, 'Jack russell'],

        // Badly overdue. Zoë is here on purpose: an accented name forces the
        // message to UCS-2 and halves the character budget, which is the thing
        // the dry run should be warning about.
        ['Alfie', 'Meg Trent', 1, 90, 'Old english sheepdog'],
        ['Zoë', 'Greg Halliday', 1, 105, 'Lhasa apso'],
        ['Tilly', 'Sam Boyd', 0, 120, 'Yorkshire terrier'],

        // One of each state, so every marker on the screen is visible without
        // anybody having to create it by hand.
        ['Poppy', 'Ella Rowe', 1, 60, 'Cocker spaniel'],
        ['Gus', 'Mark Ives', 1, 75, 'Bernese mountain dog'],
        ['Nala', 'Jane Fox', 1, 80, 'Samoyed'],
    ];

    public function handle(TenantContext $context, SmsConsent $consent, RebookMessenger $messenger): int
    {
        if (! app()->environment('local')) {
            $this->error('demo:rebooking is local only. It writes fake clients and fake visit history.');

            return self::FAILURE;
        }

        $phone = $this->phone();

        if ($phone === null) {
            $this->error('No test phone number. Pass --phone=07… or set REBOOKING_DEMO_PHONE in .env.');
            $this->line('  This is the number that will receive the one real text, so it is required.');

            return self::FAILURE;
        }

        $tenant = $this->tenant();
        $context->set($tenant);

        $owner = $this->owner($tenant);
        $groomer = $this->groomer($tenant);
        $services = $this->services();
        $this->hours([$owner, $groomer]);

        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $seeded = [];

        foreach (self::CLIENTS as [$subjectName, $ownerName, $serviceIndex, $after, $breed]) {
            $seeded[$subjectName] = $this->client(
                $tenant, $groomer, $services[$serviceIndex], $today,
                $subjectName, $ownerName, $breed, $after, null,
            );
        }

        // Scout is the one that matters. Overdue by a fortnight, so it is
        // visibly in the middle of the list rather than buried at either end.
        $scout = $this->client(
            $tenant, $groomer, $services[1], $today,
            'Scout', 'Your own mobile', 'Test line', 56, $phone,
        );

        $this->states($seeded, $consent);

        $context->clear();

        $this->summary($tenant, $owner, $scout, $phone, $messenger);

        if ($this->option('enable-sending')) {
            $messenger->enableAfterDryRun($tenant->fresh());
            $this->warn('Automatic sending is ON for this tenant. The hourly schedule will text everybody due.');
        }

        return self::SUCCESS;
    }

    /**
     * The one address the walkthrough in DEPLOY.md tells you to sign in with.
     */
    private function ownerEmail(): string
    {
        return 'owner@'.$this->option('slug').'.test';
    }

    private function phone(): ?string
    {
        $raw = (string) ($this->option('phone') ?: env('REBOOKING_DEMO_PHONE', ''));

        if (trim($raw) === '') {
            return null;
        }

        try {
            return PhoneNumber::toE164($raw);
        } catch (\Throwable $exception) {
            $this->error('That does not look like a phone number: '.$exception->getMessage());

            return null;
        }
    }

    private function tenant(): Tenant
    {
        $slug = (string) $this->option('slug');
        $existing = Tenant::query()->withoutGlobalScopes()->where('slug', $slug)->first();

        if ($existing !== null) {
            $this->line("Refilling existing tenant #{$existing->id} ({$slug}).");

            return $existing;
        }

        return Tenant::query()->create([
            'name' => 'Rebooking Demo Salon',
            'slug' => TenantSlug::generate($slug),
            'type' => 'groomer',
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
            'email' => 'salon@'.$slug.'.test',
            'phone' => '020 7946 0111',
            'address_line_1' => '1 Demo Row',
            'city' => 'London',
            'postcode' => 'E8 3AA',
            'onboarding_completed_at' => now(),
            'booking_page_live' => true,
            'subscription_status' => 'active',
            'trial_ends_at' => now()->addDays((int) config('billing.trial_days')),
        ]);
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $this->ownerEmail())
            ->first() ?? new User;

        $user->forceFill([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Owner',
            'email' => $this->ownerEmail(),
            'password' => bcrypt('password'),
            'role' => UserRole::Owner,
            'is_bookable' => true,
            'is_active' => true,
            'colour' => '#0F766E',
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function groomer(Tenant $tenant): User
    {
        $email = 'groomer@'.$this->option('slug').'.test';

        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->first() ?? new User;

        $user->forceFill([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Groomer',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => UserRole::Staff,
            'is_bookable' => true,
            'is_active' => true,
            'colour' => '#C2410C',
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    /**
     * The price list, straight out of `config/verticals.php` — the same rows a
     * real new tenant is set up with, including the `rebook_interval` each one
     * carries. Nothing here invents a price.
     *
     * @return list<Service>
     */
    private function services(): array
    {
        $services = [];

        foreach ((array) config('verticals.groomer.default_services') as $index => $row) {
            $service = Service::withoutGlobalScopes()
                ->where('tenant_id', current_tenant_id())
                ->where('name', $row['name'])
                ->first() ?? new Service;

            $service->forceFill([
                'tenant_id' => current_tenant_id(),
                'name' => $row['name'],
                'duration_minutes' => $row['duration_minutes'],
                'price' => $row['price'],
                'deposit_amount' => $row['deposit_amount'],
                'suggested_interval_days' => VerticalInterval::toDays($row['rebook_interval'] ?? null),
                'is_active' => true,
                'sort_order' => $index,
            ])->save();

            $services[] = $service;
        }

        return $services;
    }

    /**
     * @param  list<User>  $staff
     */
    private function hours(array $staff): void
    {
        foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $weekday) {
            foreach ($staff as $user) {
                $exists = AvailabilityRule::withoutGlobalScopes()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('user_id', $user->id)
                    ->where('weekday', $weekday->value)
                    ->exists();

                if ($exists) {
                    continue;
                }

                AvailabilityRule::query()->create([
                    'user_id' => $user->id,
                    'weekday' => $weekday,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                ]);
            }
        }
    }

    /**
     * One client, one subject, and a visit history two or three deep.
     *
     * More than one visit because a single appointment four months ago is not
     * what a regular looks like, and because the interval this product infers is
     * only believable next to a rhythm.
     *
     * @return array{customer: Customer, subject: Subject}
     */
    private function client(
        Tenant $tenant,
        User $groomer,
        Service $service,
        CarbonImmutable $today,
        string $subjectName,
        string $ownerName,
        string $breed,
        int $daysSinceLastVisit,
        ?string $phone,
    ): array {
        $email = Str::slug($subjectName).'.'.Str::slug($ownerName).'@'.$this->option('slug').'.test';

        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->first() ?? new Customer;

        $customer->forceFill([
            'tenant_id' => $tenant->id,
            'name' => $ownerName,
            'email' => $email,
            'phone' => $phone ?? $this->fakePhone($subjectName),
            // Reset, so a STOP sent while testing last time does not silently
            // suppress this run. `states()` re-applies the one deliberate
            // opt-out afterwards.
            'sms_opted_out_at' => null,
            'sms_opt_out_source' => null,
        ])->save();

        $subject = Subject::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('name', $subjectName)
            ->first() ?? new Subject;

        $subject->forceFill([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'name' => $subjectName,
            'attributes' => ['breed' => $breed, 'size' => 'medium'],
            // Cleared on every run so a previous experiment's snooze, stop or
            // failure count does not survive into the next one.
            'rebook_snoozed_until' => null,
            'rebook_stopped_at' => null,
            'rebook_contacted_at' => null,
            'rebook_failed_sends' => 0,
            'rebook_send_blocked_at' => null,
        ])->save();

        $interval = (int) $service->suggested_interval_days;

        // Walk backwards from the last visit at one interval a time, so the
        // history lands inside the past four months and reads as a rhythm.
        for ($visit = 0; $visit < 3; $visit++) {
            $age = $daysSinceLastVisit + ($visit * $interval);

            if ($age > 122) {
                break;
            }

            $this->visit($tenant, $groomer, $service, $customer, $subject, $today->subDays($age));
        }

        return ['customer' => $customer, 'subject' => $subject];
    }

    private function visit(
        Tenant $tenant,
        User $groomer,
        Service $service,
        Customer $customer,
        Subject $subject,
        CarbonImmutable $day,
    ): void {
        // 10:00 local, and the same instant every run — this is the key the
        // second run matches on so it updates rather than adding a visit.
        $startsAt = $day->setTime(10, 0)->utc();

        $exists = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('subject_id', $subject->id)
            ->where('starts_at', $startsAt)
            ->exists();

        if ($exists) {
            return;
        }

        Booking::query()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $groomer->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'subject_id' => $subject->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes((int) $service->duration_minutes),
            'status' => BookingStatus::Completed,
            'deposit_status' => DepositStatus::None,
            'price_at_booking' => $service->price->amount,
            'deposit_at_booking' => 0,
            'public_token' => (string) Str::uuid(),
            'source' => BookingSource::Manual,
        ]);
    }

    /**
     * @param  array<string, array{customer: Customer, subject: Subject}>  $seeded
     */
    private function states(array $seeded, SmsConsent $consent): void
    {
        $seeded['Poppy']['subject']->forceFill([
            'rebook_snoozed_until' => now()->addDays(21),
        ])->save();

        $seeded['Gus']['subject']->forceFill([
            'rebook_stopped_at' => now()->subDays(3),
        ])->save();

        $consent->optOut($seeded['Nala']['customer'], 'demo_seed');
    }

    /**
     * A number that is unmistakably not a real one.
     *
     * Ofcom reserves 07700 900000–900999 for drama and documentation. Nothing
     * seeded here can ring a stranger's phone, which is the point: a demo whose
     * fake numbers are plausible is a demo one `--ignore-window` away from
     * texting somebody's grandmother.
     */
    private function fakePhone(string $seed): string
    {
        return '+4477009'.str_pad((string) (abs(crc32($seed)) % 1000), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{customer: Customer, subject: Subject}  $scout
     */
    private function summary(Tenant $tenant, User $owner, array $scout, string $phone, RebookMessenger $messenger): void
    {
        $run = $messenger->dryRun($tenant->fresh());

        $this->line('');
        $this->info('Seeded '.$tenant->name.' (#'.$tenant->id.', '.$tenant->slug.')');
        $this->line('');
        $this->line('  Sign in       '.app_url('login'));
        $this->line('                '.$owner->email.' / password');
        $this->line('  Overdue list  '.app_url('overdue'));
        $this->line('  Booking page  '.book_url($tenant->slug));
        $this->line('');
        $this->line('  Clients       '.(count(self::CLIENTS) + 1).', with '.count(self::CLIENTS).' fake numbers on Ofcom\'s reserved range');
        $this->line('  Overdue now   '.$run['count'].' would be texted, '.$run['segments'].' segments');
        $this->line('  On the list,  '.count($run['suppressed']).' — opted out, or already asked twice');
        $this->line('   not texted');
        $this->line('  Off the list  Poppy (snoozed 21 days), Gus (stopped)');
        $this->line('  Send window   '.SendWindow::describe($tenant).' '.$tenant->timezone
            .' — '.($run['in_window'] ? 'open now' : 'CLOSED now, so a scheduled run would send nothing'));
        $this->line('');
        $this->line('  Your number   '.$phone.' — '.$scout['subject']->name.', subject #'.$scout['subject']->id);
        $this->line('');
        $this->line('  Dry run first, which sends nothing:');
        $this->line('    php artisan rebooking:send --tenant='.$tenant->slug.' --dry-run');
        $this->line('');
        $this->line('  Then one real text to that number and no others:');
        $this->line('    php artisan rebooking:send --tenant='.$tenant->slug.' --subject='.$scout['subject']->id.' --ignore-window --force');
        $this->line('');

        if (config('services.sms.driver') !== 'twilio') {
            $this->warn('  SMS_DRIVER is "'.config('services.sms.driver').'", so a send writes to the log and no text arrives.');
            $this->line('  Set SMS_DRIVER=twilio with TWILIO_SID, TWILIO_TOKEN and TWILIO_FROM to receive it.');
        }

        /*
         * The one that wastes an afternoon. `SendSms` is a queued job, so on the
         * `database` connection the command reports "1" and the text sits in
         * `jobs` until a worker takes it — which reads as a broken feature
         * rather than as a process nobody started.
         */
        if (config('queue.default') !== 'sync') {
            $this->warn('  QUEUE_CONNECTION is "'.config('queue.default').'", so the text is queued, not sent.');
            $this->line('  Run a worker in another terminal:  php artisan queue:work');
            $this->line('  Or set QUEUE_CONNECTION=sync in .env to send inline while testing.');
        }

        if (! $messenger->isEnabled($tenant->fresh())) {
            $this->line('  Automatic sending is off, which is the default and why the single send needs --force.');
            $this->line('  --force is refused without --subject, so it cannot become a way to text everybody.');
        }
    }
}
