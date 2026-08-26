<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\PreferredTime;
use App\Enums\SlotOfferStatus;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fills ONE existing tenant with enough realistic data to stress the operator
 * app: a full staff list, a real price list, months of history and a genuinely
 * busy today.
 *
 * `DemoTenantSeeder` creates a tenant and two staff and stops, which leaves the
 * diary empty — you cannot judge a diary layout against nothing. It also
 * collides on `tenants.slug` the second time you run it. This one is the
 * opposite on both counts: it points at a tenant that already exists, and it
 * deletes its own previous output before writing new output, so running it
 * five times leaves the same tenant in the same state.
 *
 * Local only, and it throws rather than returning quietly if it finds itself
 * anywhere else — it deletes rows, and a seeder that deletes rows must never be
 * one `--force` away from doing it in production.
 *
 * Scope: every read and every delete is filtered by `tenant_id`. Nothing here
 * touches another tenant's rows, and the demo staff it creates are marked with
 * an `@demo.invalid` address so the wipe can tell its own users from real ones.
 */
class DemoDataSeeder extends Seeder
{
    /** Demo-created staff carry this domain so the wipe can find exactly them. */
    private const STAFF_DOMAIN = '@demo.invalid';

    /** Demo-created failed jobs carry this uuid prefix, for the same reason. */
    private const JOB_UUID_PREFIX = 'demo-';

    private CarbonImmutable $today;

    /**
     * Entry point when run through `db:seed`. Prefer `php artisan demo:seed`,
     * which takes the tenant as a real argument.
     */
    public function run(): void
    {
        $ref = (string) env('DEMO_TENANT', '');

        if ($ref === '') {
            throw new RuntimeException(
                'DemoDataSeeder needs a tenant. Run `php artisan demo:seed {id-or-slug}` instead, '
                .'or set DEMO_TENANT=<id-or-slug>.'
            );
        }

        $this->forTenant(self::resolveTenant($ref));
    }

    /**
     * Find a tenant by numeric id or by slug.
     *
     * Not called `resolve()`: Illuminate\Database\Seeder already declares a
     * non-static `resolve()`, and redeclaring it static is a fatal error.
     */
    public static function resolveTenant(string $ref): Tenant
    {
        $query = Tenant::query()->withoutGlobalScopes();

        $tenant = ctype_digit($ref)
            ? $query->find((int) $ref)
            : $query->where('slug', $ref)->first();

        if ($tenant === null) {
            throw new RuntimeException("No tenant matches [{$ref}]. Pass a numeric id or a slug.");
        }

        return $tenant;
    }

    public function forTenant(Tenant $tenant): void
    {
        $this->guardEnvironment();

        /*
         * Deterministic. Two runs against the same tenant produce the same
         * hundred-odd bookings, which matters when you are comparing diary
         * layouts against each other and need the day to be the same day.
         */
        mt_srand(20260310 + $tenant->id);

        $this->today = CarbonImmutable::now($tenant->timezone)->startOfDay();

        app(TenantContext::class)->set($tenant);

        try {
            DB::transaction(function () use ($tenant) {
                $this->wipe($tenant);

                $staff = $this->staff($tenant);
                $services = $this->services();
                $this->assignServices($staff, $services);
                $this->availability($staff);
                $customers = $this->customers();

                $this->history($tenant, $staff, $services, $customers);
                $this->today($tenant, $staff, $services, $customers);
                $this->recovered($tenant, $staff, $services, $customers);
                $this->timeOff($staff);
                $this->messages($tenant);
            });

            $this->failures($tenant);
        } finally {
            app(TenantContext::class)->clear();
        }

        $this->report($tenant);
    }

    /**
     * Local development, and the test suite.
     *
     * `testing` was added deliberately, not as a loophole. The suggester and
     * the dashboard both have to be judged against a *real* week — 72 clients,
     * six weeks of history, a day with an overrun and a double-booking and a
     * freed slot — and a hand-built four-booking fixture cannot tell you
     * whether "your usual Tuesday" is true of anybody. Asserting against the
     * same data the screens are looked at in is the more honest test.
     *
     * It is safe there for two reasons that do not apply anywhere else: the
     * test database is `:memory:`, and `RefreshDatabase` throws the whole thing
     * away between tests. There is nothing to delete that was not created by
     * the same test.
     *
     * Every other environment — staging, production — still throws.
     */
    private function guardEnvironment(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException(
                'DemoDataSeeder refuses to run in ['.app()->environment().']. '
                .'It deletes rows and writes fake customers; it is for local development and tests only.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // Wipe — everything this seeder has ever written for THIS tenant, and
    // nothing else. Order follows the foreign keys inwards.
    // -----------------------------------------------------------------------
    private function wipe(Tenant $tenant): void
    {
        $id = $tenant->id;

        DB::table('slot_offers')->where('tenant_id', $id)->delete();
        DB::table('messages')->where('tenant_id', $id)->delete();
        DB::table('bookings')->where('tenant_id', $id)->delete();
        DB::table('waitlist_entries')->where('tenant_id', $id)->delete();
        DB::table('subjects')->where('tenant_id', $id)->delete();
        DB::table('customers')->where('tenant_id', $id)->delete();
        DB::table('time_off')->where('tenant_id', $id)->delete();
        DB::table('availability_rules')->where('tenant_id', $id)->delete();

        // service_user has no tenant_id of its own; it hangs off services.
        $serviceIds = DB::table('services')->where('tenant_id', $id)->pluck('id');
        DB::table('service_user')->whereIn('service_id', $serviceIds)->delete();
        DB::table('services')->where('tenant_id', $id)->delete();

        /*
         * Staff, but only the ones this seeder made. The owner is left alone
         * on purpose — it is the account you log in with, and deleting it
         * would lock you out of the tenant you are trying to look at.
         */
        DB::table('users')
            ->where('tenant_id', $id)
            ->where('email', 'like', '%'.self::STAFF_DOMAIN)
            ->delete();
    }

    // -----------------------------------------------------------------------
    // Staff — the tenant's existing owner plus three.
    // -----------------------------------------------------------------------
    /** @return list<User> */
    private function staff(Tenant $tenant): array
    {
        $owner = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', UserRole::Owner->value)
            ->first();

        if ($owner === null) {
            throw new RuntimeException(
                "Tenant #{$tenant->id} has no owner. This seeder fills an existing salon; it does not create one."
            );
        }

        $owner->forceFill(['is_bookable' => true, 'is_active' => true, 'colour' => '#2F5D4A'])->save();

        $team = [$owner];

        foreach ([
            ['Ana Duarte', '#7B3448'],
            ['Marek Kowalski', '#24415F'],
            ['Priya Nair', '#8A5A1E'],
        ] as [$name, $colour]) {
            $user = new User;
            $user->forceFill([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => Str::slug($name).'.'.$tenant->slug.self::STAFF_DOMAIN,
                'password' => 'password',
                'role' => UserRole::Staff,
                'is_bookable' => true,
                'is_active' => true,
                'colour' => $colour,
                'email_verified_at' => now(),
            ])->save();

            $team[] = $user;
        }

        return $team;
    }

    // -----------------------------------------------------------------------
    // Services — nine, with durations and prices a real groomer would charge.
    // Prices are pence.
    // -----------------------------------------------------------------------
    /** @return list<Service> */
    private function services(): array
    {
        $rows = [
            ['Full groom — small dog', 60, 0, 3500, 1000],
            ['Full groom — medium dog', 90, 15, 4500, 1000],
            ['Full groom — large dog', 120, 15, 5800, 1500],
            ['Full groom — double coat', 120, 15, 6200, 1500],
            ['Bath and blow dry', 45, 0, 2800, 1000],
            ['Puppy introduction groom', 45, 0, 3000, 1000],
            ['Nail clip', 15, 0, 1200, 0],
            ['De-shed treatment', 60, 0, 3800, 1000],
            ['Hand strip', 120, 15, 6500, 2000],
        ];

        $services = [];

        foreach ($rows as $i => [$name, $duration, $buffer, $price, $deposit]) {
            $services[] = Service::query()->create([
                'name' => $name,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                'price' => $price,
                'deposit_amount' => $deposit,
                'is_active' => true,
                'sort_order' => $i,
            ]);
        }

        return $services;
    }

    /**
     * Who can do what.
     *
     * Not everyone does everything: hand strip and double coats are the two
     * that take a trained pair of hands, so only two of the four are attached
     * to them. Without this pivot the availability engine finds no eligible
     * staff and the public booking page offers no slots at all — the diary
     * would look fine and booking would be silently broken.
     *
     * @param  list<User>  $staff
     * @param  list<Service>  $services
     */
    private function assignServices(array $staff, array $services): void
    {
        [$owner, $ana, $marek, $priya] = $staff;

        foreach ($services as $service) {
            $specialist = str_contains($service->name, 'Hand strip')
                || str_contains($service->name, 'double coat');

            $service->staff()->sync($specialist
                ? [$owner->id, $ana->id]
                : [$owner->id, $ana->id, $marek->id, $priya->id]);
        }
    }

    /** @param  list<User>  $staff */
    private function availability(array $staff): void
    {
        // Monday to Saturday. Saturday is a short day, which is what makes
        // Saturday look different in a week view instead of being a copy.
        foreach ($staff as $user) {
            foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday,
                Weekday::Thursday, Weekday::Friday, Weekday::Saturday] as $weekday) {
                AvailabilityRule::query()->create([
                    'user_id' => $user->id,
                    'weekday' => $weekday,
                    'start_time' => '09:00:00',
                    'end_time' => $weekday === Weekday::Saturday ? '14:00:00' : '18:00:00',
                ]);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Customers and their dogs.
    // -----------------------------------------------------------------------
    /** @return list<array{customer: Customer, subject: Subject}> */
    private function customers(): array
    {
        $first = ['Hannah', 'Tom', 'Deb', 'Cal', 'Marta', 'Ed', 'Fay', 'Greg', 'Lena', 'Sam',
            'Nadia', 'Ruth', 'Gil', 'Ines', 'Jo', 'Priya', 'Owen', 'Bex', 'Callum', 'Nia',
            'Ade', 'Ffion', 'Rory', 'Suki', 'Dev', 'Mai', 'Theo', 'Orla', 'Kit', 'Yusuf'];
        $last = ['Vaughn', 'Beckett', 'Oyelaran', 'Whitfield', 'Lis', 'Nwosu', 'Okonkwo', 'Tan',
            'Fricke', 'Iqbal', 'Rahman', 'Alderton', 'Ferreira', 'Carvalho', 'Marsh', 'Shah',
            'Pryce', 'Hollis', 'Doughty', 'Ashworth', 'Mensah', 'Gwilym', 'Sackville', 'Bright'];
        $dogs = ['Bramble', 'Suki', 'Willow', 'Pepper', 'Otto', 'Dexter', 'Nala', 'Rufus', 'Bear',
            'Marlow', 'Hazel', 'Biscuit', 'Coco', 'Juniper', 'Alfie', 'Moss', 'Dot', 'Ziggy',
            'Maple', 'Rowan', 'Bandit', 'Clover', 'Nutmeg', 'Poppy', 'Wren', 'Bruno', 'Ivy',
            'Sable', 'Tiggy', 'Basil', 'Fennel', 'Pip', 'Sorrel', 'Tansy', 'Bracken', 'Elm'];
        $breeds = ['Cockapoo', 'Labradoodle', 'Border Collie', 'Cocker Spaniel', 'Shih Tzu',
            'Golden Retriever', 'Miniature Schnauzer', 'Bichon Frise', 'Cavapoo', 'Westie',
            'Springer Spaniel', 'Samoyed', 'Bernese Mountain Dog', 'Jack Russell'];
        $sizes = ['small', 'medium', 'large', 'extra large'];
        $temperaments = [
            'Nervous with clippers around the face.',
            'Loves the dryer. No notes.',
            'Needs a muzzle for nails — owner knows.',
            'Very matted behind the ears last time.',
            'Puppy, first few visits. Keep it short.',
            null, null, null,
        ];

        $pairs = [];
        $used = [];

        for ($i = 0; $i < 72; $i++) {
            $name = $first[$i % count($first)].' '.$last[intdiv($i, 7) % count($last)];
            $email = Str::slug($name, '.').($i > 0 ? $i : '').'@example.test';

            if (isset($used[$email])) {
                continue;
            }
            $used[$email] = true;

            $customer = Customer::query()->create([
                'name' => $name,
                'email' => $email,
                'phone' => '07'.str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT),
                'notes' => mt_rand(0, 5) === 0 ? 'Prefers Saturday mornings.' : null,
            ]);

            // Most clients have one dog; a few have two, which is where the
            // "which dog is this booking for" question comes from.
            $petCount = mt_rand(0, 6) === 0 ? 2 : 1;

            for ($p = 0; $p < $petCount; $p++) {
                $subject = Subject::query()->create([
                    'customer_id' => $customer->id,
                    'name' => $dogs[($i * 2 + $p) % count($dogs)],
                    'attributes' => [
                        'breed' => $breeds[($i + $p) % count($breeds)],
                        'size' => $sizes[($i + $p) % count($sizes)],
                        'coat' => ['curly', 'double', 'wire', 'silky'][($i + $p) % 4],
                        'notes' => $temperaments[($i + $p) % count($temperaments)],
                    ],
                ]);

                $pairs[] = ['customer' => $customer, 'subject' => $subject];
            }
        }

        return $pairs;
    }

    // -----------------------------------------------------------------------
    // Six weeks behind, three weeks ahead.
    // -----------------------------------------------------------------------
    /**
     * @param  list<User>  $staff
     * @param  list<Service>  $services
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     */
    private function history(Tenant $tenant, array $staff, array $services, array $pairs): void
    {
        $slots = [9 * 60, 10 * 60, 11 * 60, 12 * 60, 13 * 60 + 30, 14 * 60 + 30, 15 * 60 + 30, 16 * 60 + 30];

        for ($offset = -42; $offset <= 21; $offset++) {
            if ($offset === 0) {
                continue;   // today is built by hand below
            }

            $day = $this->today->addDays($offset);

            if ($day->isSunday()) {
                continue;
            }

            $past = $offset < 0;
            // Saturdays are busy and short; midweek is steady.
            $count = $day->isSaturday() ? mt_rand(4, 6) : mt_rand(2, 5);

            $taken = [];

            for ($i = 0; $i < $count; $i++) {
                $member = $staff[mt_rand(0, count($staff) - 1)];
                $slot = $slots[mt_rand(0, count($slots) - 1)];
                $key = $member->id.':'.$slot;

                if (isset($taken[$key])) {
                    continue;
                }
                $taken[$key] = true;

                $service = $services[mt_rand(0, count($services) - 1)];
                $pair = $pairs[mt_rand(0, count($pairs) - 1)];

                if ($day->isSaturday() && $slot >= 14 * 60) {
                    continue;   // closed Saturday afternoon
                }

                $starts = $day->addMinutes($slot);
                $ends = $starts->addMinutes($service->duration_minutes);

                [$status, $deposit, $cancelledAt, $reason] = $this->outcome($past);

                $this->booking($tenant, $member, $service, $pair, $starts, $ends, $status, $deposit, $cancelledAt, $reason);
            }
        }
    }

    /**
     * The mix. Past days are mostly completed with a realistic tail of
     * cancellations and no-shows; future days are confirmed with a few still
     * pending a deposit.
     *
     * @return array{0: BookingStatus, 1: DepositStatus, 2: CarbonImmutable|null, 3: string|null}
     */
    private function outcome(bool $past): array
    {
        $roll = mt_rand(1, 100);

        if ($past) {
            if ($roll <= 78) {
                return [BookingStatus::Completed, mt_rand(0, 1) ? DepositStatus::Paid : DepositStatus::None, null, null];
            }
            if ($roll <= 90) {
                return [BookingStatus::Cancelled, DepositStatus::Refunded, CarbonImmutable::now(), 'Client cancelled'];
            }

            return [BookingStatus::NoShow, DepositStatus::Paid, null, null];
        }

        if ($roll <= 80) {
            return [BookingStatus::Confirmed, mt_rand(0, 1) ? DepositStatus::Paid : DepositStatus::None, null, null];
        }
        if ($roll <= 92) {
            return [BookingStatus::Pending, DepositStatus::Required, null, null];
        }

        return [BookingStatus::Cancelled, DepositStatus::Refunded, CarbonImmutable::now(), 'Client cancelled'];
    }

    /** @param  array{customer: Customer, subject: Subject}  $pair */
    private function booking(
        Tenant $tenant,
        User $staff,
        Service $service,
        array $pair,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
        BookingStatus $status,
        DepositStatus $deposit,
        ?CarbonImmutable $cancelledAt = null,
        ?string $reason = null,
    ): Booking {
        return Booking::query()->create([
            'staff_id' => $staff->id,
            'service_id' => $service->id,
            'customer_id' => $pair['customer']->id,
            'subject_id' => $pair['subject']->id,
            'starts_at' => $starts->utc(),
            'ends_at' => $ends->utc(),
            'status' => $status,
            'deposit_status' => $deposit,
            'price_at_booking' => $service->price->amount,
            'deposit_at_booking' => $service->deposit_amount->amount,
            'deposit_paid_at' => $deposit === DepositStatus::Paid ? $starts->subDays(2)->utc() : null,
            'cancelled_at' => $cancelledAt?->utc(),
            'cancellation_reason' => $reason,
            'source' => mt_rand(0, 2) === 0 ? BookingSource::Manual : BookingSource::Online,
        ]);
    }

    // -----------------------------------------------------------------------
    // Today, built by hand. Everything the diary has to be able to draw.
    // -----------------------------------------------------------------------
    /**
     * @param  list<User>  $staff
     * @param  list<Service>  $services
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     */
    private function today(Tenant $tenant, array $staff, array $services, array $pairs): void
    {
        [$owner, $ana, $marek, $priya] = $staff;

        $svc = fn (string $needle): Service => collect($services)
            ->first(fn (Service $s) => str_contains($s->name, $needle)) ?? $services[0];

        $full = $svc('medium dog');
        $bath = $svc('Bath and blow');
        $nails = $svc('Nail clip');
        $puppy = $svc('Puppy');
        $double = $svc('double coat');

        $at = fn (int $h, int $m = 0): CarbonImmutable => $this->today->addMinutes($h * 60 + $m);
        $p = fn (int $i): array => $pairs[$i % count($pairs)];

        // --- morning, done -------------------------------------------------
        $this->booking($tenant, $ana, $full, $p(0), $at(9), $at(10, 30), BookingStatus::Completed, DepositStatus::Paid);
        $this->booking($tenant, $ana, $nails, $p(1), $at(10, 30), $at(10, 45), BookingStatus::Completed, DepositStatus::None);
        $this->booking($tenant, $ana, $bath, $p(2), $at(11), $at(11, 45), BookingStatus::Completed, DepositStatus::Paid);
        $this->booking($tenant, $marek, $full, $p(3), $at(9, 15), $at(10, 45), BookingStatus::Completed, DepositStatus::Paid);
        $this->booking($tenant, $marek, $bath, $p(4), $at(10, 45), $at(11, 30), BookingStatus::Completed, DepositStatus::None);
        $this->booking($tenant, $marek, $nails, $p(5), $at(12), $at(12, 15), BookingStatus::Completed, DepositStatus::None);
        $this->booking($tenant, $priya, $bath, $p(6), $at(9, 30), $at(10, 15), BookingStatus::Completed, DepositStatus::Paid);
        $this->booking($tenant, $priya, $nails, $p(7), $at(11, 15), $at(11, 30), BookingStatus::Completed, DepositStatus::None);

        // --- in the chair around lunchtime ---------------------------------
        $this->booking($tenant, $ana, $puppy, $p(8), $at(12, 15), $at(13), BookingStatus::Confirmed, DepositStatus::Paid);

        /*
         * Runs long. The double coat is a 120-minute service and this one is
         * holding 150 minutes of the day — `ends_at` past `starts_at +
         * duration_minutes` is the only way an overrun exists in this schema,
         * and it is what the diary has to be able to draw.
         */
        $this->booking($tenant, $ana, $double, $p(9), $at(14), $at(16, 30), BookingStatus::Confirmed, DepositStatus::Paid);

        // --- the overlapping pair, both on Priya ---------------------------
        $this->booking($tenant, $priya, $full, $p(10), $at(13, 30), $at(15), BookingStatus::Confirmed, DepositStatus::Paid);
        $this->booking($tenant, $priya, $nails, $p(11), $at(13, 45), $at(14), BookingStatus::Confirmed, DepositStatus::None);

        // --- afternoon, still to come --------------------------------------
        $this->booking($tenant, $marek, $bath, $p(12), $at(13, 15), $at(14), BookingStatus::Confirmed, DepositStatus::Paid);
        $this->booking($tenant, $marek, $bath, $p(13), $at(16, 30), $at(17, 15), BookingStatus::Confirmed, DepositStatus::None);
        $this->booking($tenant, $owner, $full, $p(14), $at(15), $at(16, 30), BookingStatus::Confirmed, DepositStatus::Paid);
        $this->booking($tenant, $owner, $nails, $p(15), $at(17), $at(17, 15), BookingStatus::Pending, DepositStatus::Required);

        // --- a plain cancellation, deposit kept ----------------------------
        $this->booking(
            $tenant, $priya, $full, $p(16), $at(15), $at(16, 30),
            BookingStatus::Cancelled, DepositStatus::Paid,
            CarbonImmutable::now()->subHours(2), 'Cancelled inside notice — deposit kept',
        );

        // --- the freed slot, with three people waiting for it ---------------
        $freed = $this->booking(
            $tenant, $marek, $full, $p(17), $at(15, 30), $at(17),
            BookingStatus::Cancelled, DepositStatus::Refunded,
            CarbonImmutable::now()->startOfDay()->addHours(8)->addMinutes(12), 'Client cancelled',
        );

        $this->waitlist($tenant, $marek, $full, $freed, $pairs);
    }

    /**
     * Appointments that exist only because somebody claimed a waitlist offer.
     *
     * Without these the dashboard's headline figure — `Recovered from waitlist`,
     * the number the whole product is sold on — reads £0.00 on the demo tenant,
     * because nothing here had ever been *claimed*. Three live offers show the
     * mechanic starting; these show it finishing.
     *
     * `bookings.waitlist_entry_id` is what the dashboard counts, and it is set
     * on claim by `BookingService::claimOffer` — so a claimed offer is modelled
     * here exactly the way a real one lands: an entry that is no longer active,
     * a `Claimed` offer, and a booking pointing back at the entry.
     *
     * Spread across this calendar month so the figure is a month's recovery
     * rather than one afternoon's, and one of them left deliberately pending —
     * a refilled slot whose deposit has not arrived is money not yet recovered,
     * and the dashboard says so.
     *
     * @param  list<User>  $staff
     * @param  list<Service>  $services
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     */
    private function recovered(Tenant $tenant, array $staff, array $services, array $pairs): void
    {
        [, $ana, $marek] = $staff;

        $full = collect($services)->first(fn (Service $s) => str_contains($s->name, 'medium dog')) ?? $services[0];
        $bath = collect($services)->first(fn (Service $s) => str_contains($s->name, 'Bath and blow')) ?? $services[0];

        $month = $this->today->startOfMonth();

        // [day of the month, staff, service, status] — the last one is still
        // waiting on its deposit.
        $plan = [
            [4, $ana, $full, BookingStatus::Completed, DepositStatus::Paid],
            [11, $marek, $bath, BookingStatus::Completed, DepositStatus::Paid],
            [18, $ana, $full, BookingStatus::Completed, DepositStatus::Paid],
            [$this->today->day + 3, $marek, $full, BookingStatus::Confirmed, DepositStatus::Paid],
            [$this->today->day + 5, $ana, $bath, BookingStatus::Pending, DepositStatus::Required],
        ];

        foreach ($plan as $index => [$day, $member, $service, $status, $deposit]) {
            $pair = $pairs[(50 + $index * 3) % count($pairs)];
            $starts = $month->addDays($day - 1)->addHours(10 + ($index % 4));

            $entry = WaitlistEntry::query()->create([
                'customer_id' => $pair['customer']->id,
                'subject_id' => $pair['subject']->id,
                'service_id' => $service->id,
                'preferred_days' => [],
                'preferred_times' => PreferredTime::Any,
                // Claimed, so it is no longer waiting for anything.
                'is_active' => false,
                'expires_at' => $starts,
            ]);

            $booking = $this->booking(
                $tenant, $member, $service, $pair,
                $starts, $starts->addMinutes($service->duration_minutes),
                $status, $deposit,
            );

            $booking->forceFill(['waitlist_entry_id' => $entry->id])->save();

            SlotOffer::query()->create([
                'waitlist_entry_id' => $entry->id,
                'booking_id' => $booking->id,
                'starts_at' => $booking->starts_at,
                'ends_at' => $booking->ends_at,
                'service_id' => $service->id,
                'staff_id' => $member->id,
                'status' => SlotOfferStatus::Claimed,
                'expires_at' => $starts,
            ]);
        }
    }

    /**
     * Three active waitlist entries for the freed slot's service, each with a
     * live offer pointing at that exact slot. That is what makes the freed row
     * in the diary able to say "offer to 3 waiting" and mean it.
     *
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     */
    private function waitlist(Tenant $tenant, User $staff, Service $service, Booking $freed, array $pairs): void
    {
        foreach ([20, 21, 22] as $n => $index) {
            $pair = $pairs[$index % count($pairs)];

            $entry = WaitlistEntry::query()->create([
                'customer_id' => $pair['customer']->id,
                'subject_id' => $pair['subject']->id,
                'service_id' => $service->id,
                'preferred_days' => [$this->today->dayOfWeekIso],
                'preferred_times' => PreferredTime::Afternoon,
                'notes' => $n === 0 ? 'Any afternoon this week works.' : null,
                'is_active' => true,
                'expires_at' => $this->today->addDays(14),
            ]);

            SlotOffer::query()->create([
                'waitlist_entry_id' => $entry->id,
                'booking_id' => null,
                'starts_at' => $freed->starts_at,
                'ends_at' => $freed->ends_at,
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'status' => SlotOfferStatus::Sent,
                'expires_at' => CarbonImmutable::now()->addHours(4),
            ]);
        }

        // Plus a handful of general waitlist entries so the Waitlist screen is
        // not just the three attached to today's gap.
        foreach ([30, 33, 36, 39, 42] as $index) {
            $pair = $pairs[$index % count($pairs)];

            WaitlistEntry::query()->create([
                'customer_id' => $pair['customer']->id,
                'subject_id' => $pair['subject']->id,
                'service_id' => $service->id,
                'preferred_days' => [6],
                'preferred_times' => PreferredTime::Morning,
                'is_active' => true,
                'expires_at' => $this->today->addDays(21),
            ]);
        }
    }

    /** @param  list<User>  $staff */
    private function timeOff(array $staff): void
    {
        [, $ana, $marek] = $staff;

        TimeOff::query()->create([
            'user_id' => $marek->id,
            'starts_at' => $this->today->addDays(5)->startOfDay()->utc(),
            'ends_at' => $this->today->addDays(7)->endOfDay()->utc(),
            'reason' => 'Annual leave',
            'is_all_day' => true,
        ]);

        TimeOff::query()->create([
            'user_id' => $ana->id,
            'starts_at' => $this->today->addDays(2)->addHours(13)->utc(),
            'ends_at' => $this->today->addDays(2)->addHours(18)->utc(),
            'reason' => 'Dentist',
            'is_all_day' => false,
        ]);

        TimeOff::query()->create([
            'user_id' => $ana->id,
            'starts_at' => $this->today->subDays(9)->startOfDay()->utc(),
            'ends_at' => $this->today->subDays(9)->endOfDay()->utc(),
            'reason' => 'Training day',
            'is_all_day' => true,
        ]);
    }

    private function messages(Tenant $tenant): void
    {
        $bookings = Booking::query()->where('tenant_id', $tenant->id)->limit(25)->get();

        foreach ($bookings as $i => $booking) {
            Message::query()->create([
                'customer_id' => $booking->customer_id,
                'booking_id' => $booking->id,
                'channel' => $i % 3 === 0 ? MessageChannel::Sms : MessageChannel::Email,
                'type' => $i % 4 === 0 ? MessageType::Reminder : MessageType::BookingConfirmed,
                'to' => $i % 3 === 0 ? '07700900123' : 'client@example.test',
                'body' => 'Your appointment is confirmed.',
                'status' => match ($i % 7) {
                    0 => MessageStatus::Failed,
                    1 => MessageStatus::Queued,
                    default => MessageStatus::Delivered,
                },
            ]);
        }
    }

    /**
     * Failed jobs and webhook failures, so the super admin Failures screen has
     * something in it. These two tables are GLOBAL — no tenant_id — so only
     * this seeder's own rows are replaced, matched on a uuid prefix and on the
     * event id. A real failure sitting in that table is evidence and must not
     * be swept away by a demo seeder.
     */
    private function failures(Tenant $tenant): void
    {
        DB::table('failed_jobs')->where('uuid', 'like', self::JOB_UUID_PREFIX.'%')->delete();
        DB::table('webhook_failures')->where('event_id', 'like', 'evt_demo_%')->delete();

        $jobs = [
            ['App\\Jobs\\SendBookingReminder', 'notifications',
                "Twilio\\Exceptions\\RestException: [HTTP 400] Unable to create record: The 'To' number 07700900000 is not a valid phone number."],
            ['App\\Jobs\\SendDailyAgenda', 'default',
                'Symfony\\Component\\Mailer\\Exception\\TransportException: Connection could not be established with host "smtp:2525": stream_socket_client(): php_network_getaddresses: getaddrinfo failed'],
            ['App\\Jobs\\BlastWaitlistOffers', 'notifications',
                'Illuminate\\Database\\QueryException: SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'],
        ];

        foreach ($jobs as $i => [$class, $queue, $exception]) {
            DB::table('failed_jobs')->insert([
                'uuid' => self::JOB_UUID_PREFIX.Str::uuid(),
                'connection' => 'redis',
                'queue' => $queue,
                'payload' => json_encode([
                    'displayName' => $class,
                    'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                    'data' => ['commandName' => $class, 'tenant_id' => $tenant->id],
                ]),
                'exception' => $exception."\n\n#0 /app/vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php(98)",
                'failed_at' => now()->subHours(3 * ($i + 1)),
            ]);
        }

        foreach ([
            ['stripe', 'evt_demo_1', 'payment_intent.payment_failed', 'No booking matches payment intent pi_3Qd9demo.'],
            ['stripe', 'evt_demo_2', 'checkout.session.completed', 'Signature verification failed: timestamp outside tolerance.'],
        ] as $i => [$source, $event, $type, $message]) {
            DB::table('webhook_failures')->insert([
                'source' => $source,
                'event_id' => $event,
                'type' => $type,
                'message' => $message,
                'payload' => json_encode(['id' => $event, 'type' => $type]),
                'created_at' => now()->subHours(6 * ($i + 1)),
                'updated_at' => now()->subHours(6 * ($i + 1)),
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Billing state
    // -----------------------------------------------------------------------
    /**
     * Put a tenant on one of three billing states.
     *
     * `active`  — a paid monthly plan. Writes allowed, no banner.
     * `trial`   — 14 days of trial left. Writes allowed, trial banner shows.
     * `expired` — past due, and past the dunning window. READ ONLY: this is the
     *             state that produces "Admin is read-only until billing is up
     *             to date", so it is the one to pick when you want to see it.
     */
    public static function billing(Tenant $tenant, string $state): void
    {
        $base = [
            'is_comped' => false,
            'paused_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'dunning_started_at' => null,
            'dunning_emails_sent' => 0,
            'booking_page_live' => true,
        ];

        $tenant->forceFill(match ($state) {
            'active' => $base + [
                'subscription_status' => 'active',
                'plan' => 'monthly',
                'trial_ends_at' => null,
                'stripe_customer_id' => 'cus_demo'.$tenant->id,
                'stripe_subscription_id' => 'sub_demo'.$tenant->id,
            ],
            'trial' => $base + [
                'subscription_status' => 'trial',
                'plan' => null,
                'trial_ends_at' => now()->addDays(14),
                'stripe_subscription_id' => null,
            ],
            'expired' => $base + [
                'subscription_status' => 'past_due',
                'plan' => 'monthly',
                'trial_ends_at' => now()->subDays(60),
                // Well outside config('billing.dunning_days'), so write access
                // has genuinely lapsed rather than being inside the grace period.
                'dunning_started_at' => now()->subDays(30),
                'dunning_emails_sent' => 3,
            ],
            default => throw new RuntimeException("Unknown billing state [{$state}]. Use active, trial or expired."),
        })->save();
    }

    private function report(Tenant $tenant): void
    {
        $count = fn (string $table): int => (int) DB::table($table)->where('tenant_id', $tenant->id)->count();

        $this->command?->info(sprintf(
            'Filled "%s" (#%d): %d staff, %d services, %d clients, %d dogs, %d bookings, %d waitlist, %d time off.',
            $tenant->name,
            $tenant->id,
            (int) DB::table('users')->where('tenant_id', $tenant->id)->count(),
            $count('services'),
            $count('customers'),
            $count('subjects'),
            $count('bookings'),
            $count('waitlist_entries'),
            $count('time_off'),
        ));

        $today = (int) DB::table('bookings')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('starts_at', [$this->today->utc(), $this->today->addDay()->utc()])
            ->count();

        $this->command?->info("Today has {$today} bookings, including two that overlap, one that runs long and one freed slot with 3 waiting.");
    }
}
