<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Stripe\StripeGateway;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        app(TenantContext::class)->clear();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * Sign in as an operator — and leave the tenant context empty for the request.
 *
 * This helper used to set `TenantContext` by hand before returning. That one
 * line is why 350 tests missed the route-binding order bug: `TenantContext` is
 * a singleton for the life of the process, so the context was already there
 * when `SubstituteBindings` ran and the middleware order could never matter.
 * The suite was testing a process that had been set up the way `ResolveTenant`
 * was supposed to set it up, and every model-bound operator screen was
 * returning 404 in a browser while every test passed.
 *
 * So it does not set one any more, and clears whatever a fixture left behind.
 * Every HTTP test in the suite now goes through the real middleware, which is
 * the only place a request ever gets a context in production.
 *
 * The whole suite passed unchanged on the day this was flipped — no test was
 * relying on the pre-set context to *assert* anything. What some of them need
 * is a context to *write* fixtures with, because `BelongsToTenant` refuses to
 * create a tenant-owned model without one. That is an arrange-phase concern:
 * set it yourself before building rows, the way `aSalonWithOneOfEverything()`
 * in MiddlewareTenancyTest does, and let this helper clear it.
 */
function actingAsTenant(User $user): TestCase
{
    $case = test()->actingAs($user);
    app(TenantContext::class)->clear();

    return $case;
}

/**
 * A salon that can take a Tuesday 09:00-17:00 booking.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{tenant: Tenant, staff: User, service: Service}
 */
function aSalon(array $overrides = []): array
{
    $tenant = Tenant::factory()->create(array_merge([
        'timezone' => 'Europe/London',
        'country' => 'GB',
    ], $overrides['tenant'] ?? []));

    if ($tenant->stripe_account_id) {
        app(StripeGateway::class)->completeAccount($tenant->stripe_account_id);
    }

    $staff = User::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'is_bookable' => true,
        'is_active' => true,
    ], $overrides['staff'] ?? []));

    $service = Service::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'price' => 3500,
        'deposit_amount' => 0,
    ], $overrides['service'] ?? []));

    $service->staff()->attach($staff->id);

    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    return compact('tenant', 'staff', 'service');
}

/** A salon wired up to Stripe Connect with a deposit. */
function aConnectedSalon(int $deposit = 1000, string $account = 'acct_test'): array
{
    return aSalon([
        'tenant' => ['stripe_account_id' => $account, 'stripe_onboarding_complete' => true],
        'service' => ['deposit_amount' => $deposit],
    ]);
}

/** @return array<string, mixed> */
function aBookingPayload(Service $service, User $staff, CarbonImmutable $startsAt, string $email = 'alex@example.com'): array
{
    return [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $startsAt->toIso8601String(),
        'name' => 'Alex Reed',
        'email' => $email,
        'phone' => '07700900000',
        'subject_name' => 'Willow',
        'subject_attributes' => ['breed' => 'Labrador', 'size' => 'medium'],
    ];
}

/*
|--------------------------------------------------------------------------
| Fixtures used by more than one file
|--------------------------------------------------------------------------
|
| Below this line is the only place a helper may be shared between test files.
|
| A helper declared inside a test file exists only once that file has been
| loaded. Serially that is invisible — every file gets loaded eventually, in
| alphabetical order, and `DiaryFreedSlotTest` happens to come before
| `DiaryGapsTest`. In parallel the two land in different workers and the second
| one dies with `Call to undefined function`. So the suite was green and
| `--parallel` was fatal on the same code, which is the worst shape a test
| failure can have: it depends on how you ran it.
|
| `tests/Pest.php` is loaded by every worker before any test file, so a helper
| here is always defined. `TestHelperScopeTest` fails the build if a helper
| declared anywhere else is called across a file boundary again.
|
| The other half of that rule: two files may not declare the same helper name,
| because a redeclaration is a hard fatal rather than a failed test.
| `MiddlewareTenancyTest` declares its own `onTheRealHosts()` instead of
| borrowing `SurfaceRoutingTest`'s `withSubdomains()` for exactly this reason.
| A helper used by one file stays in that file; one used by two comes here.
|
*/

/**
 * A salon in the beta programme. BetaSandbox — see BETA_SANDBOX.md.
 *
 * Here rather than in one of the sandbox test files because five of them need
 * it, and a helper declared in a test file is undefined in every other worker
 * under `--parallel` — see the note above.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{tenant: Tenant, staff: User, service: Service}
 */
function aBetaSalon(array $overrides = []): array
{
    $salon = aSalon($overrides);
    $salon['tenant']->forceFill(['is_beta' => true])->save();

    return [...$salon, 'tenant' => $salon['tenant']->fresh()];
}

/**
 * One confirmed booking in a salon, written straight to the table.
 *
 * @param  array{tenant: Tenant, staff: User, service: Service}  $salon
 */
function aSandboxBooking(array $salon, string $startsAt): Booking
{
    $context = app(TenantContext::class);
    $context->set($salon['tenant']);

    try {
        $starts = CarbonImmutable::parse($startsAt, 'Europe/London');

        $customer = Customer::query()->create([
            'name' => 'Sam Reed',
            'email' => 'sam.'.$salon['tenant']->id.'@example.test',
            'phone' => '07700900123',
        ]);

        return Booking::query()->create([
            'staff_id' => $salon['staff']->id,
            'service_id' => $salon['service']->id,
            'customer_id' => $customer->id,
            'starts_at' => $starts->utc(),
            'ends_at' => $starts->addHour()->utc(),
            'status' => BookingStatus::Confirmed,
            'deposit_status' => DepositStatus::None,
            'price_at_booking' => 3500,
            'deposit_at_booking' => 0,
            'source' => BookingSource::Online,
        ]);
    } finally {
        $context->clear();
    }
}

/**
 * A salon with a diary, a Wednesday, and one of everything a day view draws.
 *
 * @return array{tenant: Tenant, user: User, staff: User, service: Service, customer: Customer}
 */
function aDiarySalon(): array
{
    test()->travelTo(CarbonImmutable::parse('2026-08-19 13:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);

    return [
        'tenant' => $tenant,
        'user' => User::factory()->create(['tenant_id' => $tenant->id]),
        'staff' => User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true]),
        'service' => Service::factory()->create(['tenant_id' => $tenant->id, 'duration_minutes' => 90]),
        'customer' => Customer::factory()->create(['tenant_id' => $tenant->id]),
    ];
}

/** @param  array<string, mixed>  $overrides */
function aDiaryBooking(array $salon, string $from, string $to, array $overrides = []): Booking
{
    return Booking::factory()->create(array_merge([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $salon['customer']->id,
        'starts_at' => CarbonImmutable::parse($from, 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse($to, 'Europe/London')->utc(),
        'status' => BookingStatus::Confirmed,
    ], $overrides));
}
