<?php

namespace App\BetaSandbox;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\PreferredTime;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\Message;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Loyalty\Loyalty;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Load sample data" — a lived-in shop, invented on the spot.
 *
 * A beta tester's first problem is that an empty diary teaches nothing. You
 * cannot tell whether the week view is legible, whether the overdue list finds
 * the right people, or what a cancellation does to the waitlist, against a shop
 * with no customers in it. This fills one with about two months of plausible
 * history and three weeks of what is still to come.
 *
 * **Replace on run, not additive.** Pressing it twice does not double anything:
 * it clears the shop's transactional data first — the same wipe "Reset my shop"
 * performs, from the same list — and lays down a fresh set. The alternative,
 * adding another cohort each time, degrades exactly as you would expect: the
 * third press leaves seventy-two customers and four hundred bookings in a
 * diary that is supposed to be readable, and the owner has no way back short of
 * a reset anyway. Because it deletes, the button carries a confirmation dialog
 * that says what it will replace.
 *
 * **It builds on the shop's real setup and never invents any.** The staff, the
 * services, the opening hours and the prices are the owner's own, so the sample
 * week is the week their salon would actually have. If there is no active
 * service or nobody bookable it refuses with a sentence saying so, rather than
 * quietly producing an empty diary or inventing services the owner would then
 * have to delete.
 *
 * **Deterministic per tenant.** The random stream is seeded from the tenant id,
 * so a reload produces the same shop — which is what makes "does this look
 * right?" a question you can ask twice.
 *
 * **Nothing is sent.** The whole run happens inside `SandboxMute`, phone
 * numbers come from Ofcom's reserved 07700 900xxx drama range, and email
 * addresses are on the reserved `.test` domain. Rows are written directly
 * rather than through `BookingService`, so no confirmation, no reminder and no
 * loyalty stamp is triggered by the load itself.
 */
final class SampleData
{
    /** Ofcom reserves 07700 900000-900999 for drama. No handset is ever on one. */
    private const PHONE_PREFIX = '07700900';

    public const DECLINE_LABEL = 'Always declines — test card';

    public const SIZES = [
        'quiet' => ['customers' => 5, 'bookings' => 10, 'waitlist' => 2],
        'typical' => ['customers' => 24, 'bookings' => 115, 'waitlist' => 4],
        'busy' => ['customers' => 64, 'bookings' => 320, 'waitlist' => 8],
    ];

    /** Marks a row as invented, on the one screen an owner would wonder about. */
    private const LABEL = 'Sample data.';

    private const FIRST_NAMES = [
        'Hannah', 'Tom', 'Deb', 'Cal', 'Marta', 'Ed', 'Fay', 'Greg', 'Lena', 'Sam',
        'Nadia', 'Ruth', 'Gil', 'Ines', 'Jo', 'Priya', 'Owen', 'Bex', 'Callum', 'Nia',
        'Ade', 'Ffion', 'Rory', 'Suki',
    ];

    private const LAST_NAMES = [
        'Vaughn', 'Beckett', 'Oyelaran', 'Whitfield', 'Lis', 'Nwosu', 'Okonkwo', 'Tan',
        'Fricke', 'Iqbal', 'Rahman', 'Alderton', 'Ferreira', 'Carvalho', 'Marsh', 'Shah',
        'Pryce', 'Hollis', 'Doughty', 'Ashworth', 'Mensah', 'Gwilym', 'Sackville', 'Bright',
    ];

    private const SUBJECT_NAMES = [
        'Bramble', 'Suki', 'Willow', 'Pepper', 'Otto', 'Dexter', 'Nala', 'Rufus', 'Bear',
        'Marlow', 'Hazel', 'Biscuit', 'Coco', 'Juniper', 'Alfie', 'Moss', 'Dot', 'Ziggy',
        'Maple', 'Rowan', 'Bandit', 'Clover', 'Nutmeg', 'Poppy',
    ];

    public function __construct(private SandboxReset $reset, private Loyalty $loyalty) {}

    /**
     * @return list<array{key: string, label: string, customers: int, bookings: int}>
     */
    public static function sizeOptions(): array
    {
        return [
            ['key' => 'quiet', 'label' => 'Quiet shop', 'customers' => 5, 'bookings' => 10],
            ['key' => 'typical', 'label' => 'Typical shop', 'customers' => 24, 'bookings' => 115],
            ['key' => 'busy', 'label' => 'Busy shop', 'customers' => 64, 'bookings' => 320],
        ];
    }

    /**
     * @param  'quiet'|'typical'|'busy'  $size
     * @return array{customers: int, bookings: int, waitlist: int, loyalty: int}
     *
     * @throws SandboxNotReady when the shop has nothing to build a diary from.
     */
    public function load(Tenant $tenant, string $size = 'typical'): array
    {
        BetaSandbox::guard($tenant);

        abort_unless(array_key_exists($size, self::SIZES), 422);

        $context = app(TenantContext::class);
        $previous = $context->tenant();
        $context->set($tenant);

        try {
            return SandboxMute::while(function () use ($tenant, $size): array {
                $staff = $this->bookableStaff($tenant);
                $services = $this->activeServices($tenant);

                if ($staff === [] || $services === []) {
                    throw SandboxNotReady::forTenant();
                }

                /*
                 * One transaction around the wipe *and* the rebuild. They are
                 * separate operations with separate reasons to fail, and the
                 * outcome nobody can recover from is the one in between: a shop
                 * emptied by a load that then threw. Either the owner gets the
                 * new sample shop or they keep the one they had.
                 *
                 * `SandboxReset::run` opens a transaction of its own; nested,
                 * that is a savepoint, so it commits with this one or not at all.
                 */
                return DB::transaction(function () use ($tenant, $staff, $services, $size): array {
                    // Replace, never accumulate. Same list as "Reset my shop",
                    // so there is one definition of what a shop's data is.
                    $this->reset->run($tenant);

                    return $this->build($tenant, $staff, $services, $size);
                });
            });
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    /**
     * @param  list<User>  $staff
     * @param  list<Service>  $services
     * @param  'quiet'|'typical'|'busy'  $size
     * @return array{customers: int, bookings: int, waitlist: int, loyalty: int}
     */
    private function build(Tenant $tenant, array $staff, array $services, string $size): array
    {
        /*
         * Seeded from the tenant id, so the same shop reloads to the same shop
         * and two beta salons do not get an identical diary.
         */
        mt_srand(20260906 + $tenant->id + (ord($size[0]) * 17));

        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $plan = self::SIZES[$size];

        $pairs = $this->customers($plan['customers']);
        $bookings = $this->diary($tenant, $today, $staff, $services, $pairs, $plan['bookings'], $size === 'busy');
        $waitlist = $this->waitlist($today, $services, $pairs, $plan['waitlist']);
        $this->sendLog($pairs, $bookings);
        $loyalty = $this->loyalty($tenant, $pairs);

        /*
         * People, not customer-and-pet pairs. A few clients have two pets, so
         * `count($pairs)` is larger than the number of customers — and this
         * figure is read straight out onto the screen as "24 customers".
         */
        $people = count(array_unique(array_map(
            fn (array $pair): int => (int) $pair['customer']->id,
            $pairs,
        )));

        return [
            'customers' => $people,
            'bookings' => count($bookings),
            'waitlist' => $waitlist,
            'loyalty' => $loyalty,
        ];
    }

    /**
     * Clients and their pets, including one whose card is a known Stripe decline.
     *
     * @return list<array{customer: Customer, subject: Subject}>
     */
    private function customers(int $count): array
    {
        $pairs = [];
        $firsts = count(self::FIRST_NAMES);
        $lasts = count(self::LAST_NAMES);

        for ($i = 0; $i < $count; $i++) {
            $decline = $i === $count - 1;
            $name = $decline
                ? 'Pat Cardwell'
                : $this->personName($i, $firsts, $lasts);

            $customer = Customer::query()->create([
                'name' => $name,
                // `.test` is reserved by RFC 6761 and resolves nowhere, so an
                // address here cannot reach a real inbox even if something one
                // day tries to send to it.
                'email' => Str::slug($name, '.').'.'.$i.'@example.test',
                'phone' => self::PHONE_PREFIX.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'notes' => $decline
                    ? self::DECLINE_LABEL
                    : self::LABEL.($i % 5 === 0 ? ' Prefers Saturday mornings.' : ''),
            ]);

            $petCount = $i % 7 === 0 ? 2 : 1;

            for ($p = 0; $p < $petCount; $p++) {
                $subject = Subject::query()->create([
                    'customer_id' => $customer->id,
                    'name' => self::SUBJECT_NAMES[($i * 2 + $p) % count(self::SUBJECT_NAMES)],
                    'attributes' => ['notes' => self::LABEL],
                ]);

                $pairs[] = ['customer' => $customer, 'subject' => $subject];
            }
        }

        return $pairs;
    }

    private function personName(int $i, int $firsts, int $lasts): string
    {
        $base = self::FIRST_NAMES[$i % $firsts].' '.self::LAST_NAMES[($i * 5 + 3) % $lasts];
        $cycle = intdiv($i, $firsts);

        return $cycle === 0 ? $base : $base.' '.chr(ord('B') + $cycle - 1);
    }

    /**
     * Five weeks behind and three ahead, on the shop's own services and staff.
     *
     * The spread of outcomes is the point. Past days are mostly completed with
     * a real tail of cancellations and no-shows, because a dashboard whose
     * no-show rate is zero tells an owner nothing about the feature they are
     * being asked to evaluate. Future days are mostly confirmed with a few
     * still pending, which is what makes "release expired holds" and the
     * request queue visible after a fast-forward.
     *
     * @param  list<User>  $staff
     * @param  list<Service>  $services
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     * @return list<Booking>
     */
    private function diary(
        Tenant $tenant,
        CarbonImmutable $today,
        array $staff,
        array $services,
        array $pairs,
        int $target,
        bool $busy,
    ): array {
        $horizon = $busy ? 42 : 35;
        $ahead = $busy ? 28 : 21;
        $slots = $busy
            ? [9 * 60, 9 * 60 + 45, 10 * 60 + 30, 11 * 60 + 15, 12 * 60, 12 * 60 + 45, 13 * 60 + 30, 14 * 60 + 15, 15 * 60, 15 * 60 + 45, 16 * 60 + 30]
            : [9 * 60, 10 * 60 + 30, 12 * 60, 13 * 60 + 30, 15 * 60, 16 * 60 + 30];

        $days = [];

        for ($offset = -$horizon; $offset <= $ahead; $offset++) {
            $day = $today->addDays($offset);

            if ($day->isSunday()) {
                continue;
            }

            $days[] = [$day, $offset];
        }

        $created = [];
        $taken = [];

        $place = function (User $member, Service $service, array $pair, CarbonImmutable $day, int $slot, int $offset) use (&$created, &$taken): bool {
            $key = $member->id.':'.$day->toDateString().':'.$slot;

            if (isset($taken[$key])) {
                return false;
            }

            $taken[$key] = true;
            $starts = $day->addMinutes($slot);
            $created[] = $this->booking(
                $member,
                $service,
                $pair,
                $starts,
                $starts->addMinutes(max(15, (int) $service->duration_minutes)),
                $offset,
            );

            return true;
        };

        if ($busy) {
            foreach ($days as [$day, $offset]) {
                if ($offset > 0 && ! $day->isSaturday()) {
                    foreach ($staff as $member) {
                        foreach ($slots as $slot) {
                            $place(
                                $member,
                                $services[mt_rand(0, count($services) - 1)],
                                $pairs[mt_rand(0, count($pairs) - 1)],
                                $day,
                                $slot,
                                $offset,
                            );
                        }
                    }

                    break;
                }
            }
        }

        $n = 0;

        while (count($created) < $target) {
            [$day, $offset] = $days[$n % count($days)];
            $member = $staff[$n % count($staff)];
            $slot = $slots[intdiv($n, count($staff)) % count($slots)];
            $place(
                $member,
                $services[$n % count($services)],
                $pairs[$n % count($pairs)],
                $day,
                $slot,
                $offset,
            );
            $n++;

            if ($n > $target * 40) {
                break;
            }
        }

        return $created;
    }

    /**
     * The mix of outcomes, by whether the day has happened.
     *
     * Today is treated as the past for statuses — a diary whose morning is
     * still "confirmed" at four in the afternoon is the one thing an owner
     * looking at today would notice immediately.
     *
     * @param  array{customer: Customer, subject: Subject}  $pair
     */
    private function booking(
        User $staff,
        Service $service,
        array $pair,
        CarbonImmutable $starts,
        CarbonImmutable $ends,
        int $offset,
    ): Booking {
        $roll = mt_rand(1, 100);

        [$status, $deposit] = match (true) {
            $offset > 0 && $roll <= 82 => [BookingStatus::Confirmed, DepositStatus::Paid],
            $offset > 0 && $roll <= 93 => [BookingStatus::Pending, DepositStatus::Required],
            $offset > 0 => [BookingStatus::Cancelled, DepositStatus::Refunded],
            $roll <= 76 => [BookingStatus::Completed, DepositStatus::Paid],
            $roll <= 89 => [BookingStatus::Cancelled, DepositStatus::Refunded],
            default => [BookingStatus::NoShow, DepositStatus::Paid],
        };

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
            'cancelled_at' => $status === BookingStatus::Cancelled ? $starts->subDay()->utc() : null,
            'cancellation_reason' => $status === BookingStatus::Cancelled ? 'Client cancelled' : null,
            'source' => mt_rand(0, 2) === 0 ? BookingSource::Manual : BookingSource::Online,
            /*
             * A pending future booking is a *checkout hold*, not a request:
             * `request_expires_at` stays null so `bookings:release-expired` is
             * the automation that picks it up after a fast-forward. Its age is
             * what that command measures, and a row created just now is not yet
             * old enough — which is exactly the point: it becomes old enough
             * when time moves.
             */
        ]);
    }

    /**
     * Four people waiting, on services and days the shop actually offers.
     *
     * @param  list<Service>  $services
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     */
    private function waitlist(CarbonImmutable $today, array $services, array $pairs, int $count): int
    {
        $times = [PreferredTime::Any, PreferredTime::Morning, PreferredTime::Afternoon, PreferredTime::Any];

        for ($i = 0; $i < $count; $i++) {
            $preferred = $times[$i % count($times)];
            $pair = $pairs[($i * 5 + 2) % count($pairs)];

            WaitlistEntry::query()->create([
                'customer_id' => $pair['customer']->id,
                'subject_id' => $pair['subject']->id,
                'service_id' => $services[$i % count($services)]->id,
                'preferred_days' => $i % 2 === 0 ? ['saturday'] : [],
                'preferred_times' => $preferred,
                'notes' => self::LABEL,
                'is_active' => true,
                // Inside the sandbox's own fast-forward reach: one press of
                // "Skip 1 week" retires the first two and leaves the rest.
                'expires_at' => $today->addDays(3 + $i * 4)->utc(),
            ]);
        }

        return $count;
    }

    /**
     * A few rows in the send log, so it is not the one empty screen.
     *
     * Written directly rather than by asking `Notifier` to send them. These
     * describe messages that went out weeks ago, in a shop that did not exist
     * until a moment ago; routing them through the notifier would mean
     * inventing a delivery that never happened and then muting it.
     *
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     * @param  list<Booking>  $bookings
     */
    private function sendLog(array $pairs, array $bookings): void
    {
        $confirmed = array_values(array_filter(
            $bookings,
            fn (Booking $booking) => $booking->status === BookingStatus::Confirmed,
        ));

        foreach (array_slice($confirmed, 0, 6) as $booking) {
            $customer = $pairs[0]['customer'];

            foreach ($pairs as $pair) {
                if ($pair['customer']->id === $booking->customer_id) {
                    $customer = $pair['customer'];

                    break;
                }
            }

            Message::query()->create([
                'customer_id' => $customer->id,
                'booking_id' => $booking->id,
                'channel' => MessageChannel::Sms,
                'type' => MessageType::BookingConfirmed,
                'to' => (string) $customer->phone,
                'body' => 'Confirmed '.$booking->starts_at->format('j M H:i').'. '.self::LABEL,
                'segments' => 1,
                'status' => MessageStatus::Sent,
            ]);
        }
    }

    /**
     * One regular, three quarters of the way to a free session.
     *
     * Only when the shop has loyalty switched on and a package configured — the
     * feature is opt-in and a sandbox must not make it look otherwise. The
     * enrolment is written at a count rather than earned by completing
     * appointments, because a stamp is earned on the *transition* to completed
     * and these bookings are created completed.
     *
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     */
    private function loyalty(Tenant $tenant, array $pairs): int
    {
        $package = $this->loyalty->activePackage($tenant);

        if ($package === null || $pairs === []) {
            return 0;
        }

        $required = (int) $package->sessions_required;

        LoyaltyEnrolment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $pairs[0]['customer']->id,
            'loyalty_package_id' => $package->id,
            'stamps_used' => max(1, $required - 2),
            'cycles_completed' => 1,
        ]);

        return 1;
    }

    /** @return list<User> */
    private function bookableStaff(Tenant $tenant): array
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_bookable', true)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @return list<Service> */
    private function activeServices(Tenant $tenant): array
    {
        return Service::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }
}
