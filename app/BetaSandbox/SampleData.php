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

final class SampleData
{
    private const PHONE_PREFIX = '07700900';

    public const DECLINE_LABEL = 'Always declines — test card';

    public const SIZES = [
        'quiet' => ['customers' => 5, 'bookings' => 10, 'waitlist' => 2],
        'typical' => ['customers' => 24, 'bookings' => 115, 'waitlist' => 4],
        'busy' => ['customers' => 64, 'bookings' => 320, 'waitlist' => 8],
    ];

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

    /** @return list<array{key: string, label: string, customers: int, bookings: int}> */
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

                return DB::transaction(function () use ($tenant, $staff, $services, $size): array {
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
        mt_srand(20260906 + $tenant->id + (ord($size[0]) * 17));

        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $plan = self::SIZES[$size];

        $pairs = $this->customers($plan['customers']);
        $bookings = $this->diary($tenant, $today, $staff, $services, $pairs, $plan['bookings'], $size === 'busy');
        $waitlist = $this->waitlist($today, $services, $pairs, $plan['waitlist']);
        $this->sendLog($pairs, $bookings);
        $loyalty = $this->loyalty($tenant, $pairs);

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

    /** @return list<array{customer: Customer, subject: Subject}> */
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

    /** @param  array{customer: Customer, subject: Subject}  $pair */
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
        ]);
    }

    /**
     * @param  list<Service>  $services
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     */
    private function waitlist(CarbonImmutable $today, array $services, array $pairs, int $count): int
    {
        $times = [PreferredTime::Any, PreferredTime::Morning, PreferredTime::Afternoon, PreferredTime::Any];
        $waiting = $this->distinctCustomers($pairs, 2, $count);

        foreach ($waiting as $i => $pair) {
            $preferred = $times[$i % count($times)];

            WaitlistEntry::query()->create([
                'customer_id' => $pair['customer']->id,
                'subject_id' => $pair['subject']->id,
                'service_id' => $services[$i % count($services)]->id,
                'preferred_days' => $i % 2 === 0 ? ['saturday'] : [],
                'preferred_times' => $preferred,
                'notes' => self::LABEL,
                'is_active' => true,
                'expires_at' => $today->addDays(3 + $i * 4)->utc(),
            ]);
        }

        return count($waiting);
    }

    /**
     * @param  list<array{customer: Customer, subject: Subject}>  $pairs
     * @return list<array{customer: Customer, subject: Subject}>
     */
    private function distinctCustomers(array $pairs, int $from, int $count): array
    {
        $picked = [];
        $seen = [];

        for ($step = 0; $step < count($pairs) && count($picked) < $count; $step++) {
            $pair = $pairs[($from + $step) % count($pairs)];

            if (isset($seen[$pair['customer']->id])) {
                continue;
            }

            $seen[$pair['customer']->id] = true;
            $picked[] = $pair;
        }

        return $picked;
    }

    /**
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

    /** @param  list<array{customer: Customer, subject: Subject}>  $pairs */
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
