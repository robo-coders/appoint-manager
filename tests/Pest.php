<?php

use App\Enums\Weekday;
use App\Models\AvailabilityRule;
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

function actingAsTenant(User $user): TestCase
{
    if ($user->tenant) {
        app(TenantContext::class)->set($user->tenant);
    }

    return test()->actingAs($user);
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
