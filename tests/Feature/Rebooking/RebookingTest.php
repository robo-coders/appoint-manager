<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Rebooking\OverdueSubjects;
use App\Services\Rebooking\RebookInterval;
use App\Services\Rebooking\RebookMessenger;
use App\Support\TenantContext;
use App\Support\VerticalInterval;
use Carbon\CarbonImmutable;

function aRebookSalon(array $service = []): array
{
    test()->travelTo(CarbonImmutable::parse('2026-08-30 10:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    app(TenantContext::class)->set($tenant);

    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'suggested_interval_days' => 42,
        'price' => 3500,
    ], $service));
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+447700900111']);
    $subject = Subject::factory()->create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'name' => 'Bella']);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    return compact('tenant', 'staff', 'service', 'customer', 'subject');
}

function aPastVisit(array $salon, string $localStart, array $overrides = []): Booking
{
    $start = CarbonImmutable::parse($localStart, 'Europe/London')->utc();

    return Booking::factory()->create(array_merge([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $salon['customer']->id,
        'subject_id' => $salon['subject']->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
        'status' => BookingStatus::Confirmed,
        'price_at_booking' => $salon['service']->price->amount,
    ], $overrides));
}

it('resolves interval from the service when nothing else is set', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);

    expect(app(RebookInterval::class)->days($salon['subject'], $salon['service']))->toBe(42);
});

it('prefers the subject interval over the service default', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    $salon['subject']->forceFill(['rebook_interval_days' => 70])->save();

    expect(app(RebookInterval::class)->days($salon['subject'], $salon['service']))->toBe(70);
});

it('prefers a checkout override over the subject and the service', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    $salon['subject']->forceFill(['rebook_interval_days' => 70])->save();

    expect(app(RebookInterval::class)->days($salon['subject'], $salon['service'], 49))->toBe(49);
});

it('writes a checkout override onto the subject', function () {
    $salon = aRebookSalon();
    $starts = CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London')->utc();

    app(BookingService::class)->create(
        $salon['tenant'],
        $salon['service'],
        $salon['staff'],
        $salon['customer'],
        $starts,
        BookingSource::Manual,
        $salon['subject'],
        rebookIntervalDays: 49,
    );

    expect($salon['subject']->fresh()->rebook_interval_days)->toBe(49)
        ->and(Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->value('rebook_interval_days'))->toBe(49);
});

it('is not overdue the day before due', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-20 09:00:00');

    $rows = app(OverdueSubjects::class)->forTenant($salon['tenant']);

    expect($rows)->toHaveCount(0);
});

it('is due on the day of', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-19 09:00:00');

    $rows = app(OverdueSubjects::class)->forTenant($salon['tenant']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['due_on'])->toBe('2026-08-30')
        ->and($rows[0]['due_label'])->toBe('30 August');
});

it('is overdue the day after due', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-18 09:00:00');

    $rows = app(OverdueSubjects::class)->forTenant($salon['tenant']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['days_overdue'])->toBe(1);
});

it('excludes a snoozed subject', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-18 09:00:00');
    $salon['subject']->forceFill(['rebook_snoozed_until' => CarbonImmutable::parse('2026-09-10', 'Europe/London')])->save();

    expect(app(OverdueSubjects::class)->forTenant($salon['tenant']))->toHaveCount(0);
});

it('excludes a subject marked contacted in the last 14 days', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-18 09:00:00');
    $salon['subject']->forceFill(['rebook_contacted_at' => now()])->save();

    expect(app(OverdueSubjects::class)->forTenant($salon['tenant']))->toHaveCount(0);
});

it('excludes a stopped subject', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-18 09:00:00');
    $salon['subject']->forceFill(['rebook_stopped_at' => now()])->save();

    expect(app(OverdueSubjects::class)->forTenant($salon['tenant']))->toHaveCount(0)
        ->and(app(OverdueSubjects::class)->stoppedForTenant($salon['tenant']))->toHaveCount(1);
});

it('sums usual service prices for the overdue band', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42, 'price' => 3500]);
    aPastVisit($salon, '2026-07-18 09:00:00');

    $other = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $max = Subject::factory()->create(['tenant_id' => $salon['tenant']->id, 'customer_id' => $other->id, 'name' => 'Max']);
    $expensive = Service::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'suggested_interval_days' => 42,
        'price' => 4500,
    ]);
    Booking::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $expensive->id,
        'customer_id' => $other->id,
        'subject_id' => $max->id,
        'starts_at' => CarbonImmutable::parse('2026-07-18 11:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-07-18 12:00:00', 'Europe/London')->utc(),
        'status' => BookingStatus::Confirmed,
        'price_at_booking' => 4500,
    ]);

    $summary = app(OverdueSubjects::class)->summary($salon['tenant']);

    expect($summary['count'])->toBe(2)
        ->and($summary['amount'])->toBe(8000)
        ->and($summary['value'])->toBe('£80.00');
});

it('does not leak another tenant\'s overdue subjects', function () {
    $ours = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($ours, '2026-07-18 09:00:00');

    $theirs = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($theirs, '2026-07-18 09:00:00');

    $rows = app(OverdueSubjects::class)->forTenant($ours['tenant']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject_id'])->toBe($ours['subject']->id)
        ->and($rows->pluck('subject_id'))->not->toContain($theirs['subject']->id);
});

it('converts vertical weeks and months to days without a code change', function () {
    expect(VerticalInterval::toDays(['value' => 6, 'unit' => 'weeks']))->toBe(42)
        ->and(VerticalInterval::toDays(['value' => 6, 'unit' => 'months']))->toBe(180)
        ->and(VerticalInterval::daysForNamedService('groomer', 'Nail clip'))->toBe(21);
});

it('shows the overdue band on the dashboard', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-18 09:00:00');
    $user = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    actingAsTenant($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('band.overdue.count', 1)
            ->where('band.overdue.value', '£35.00'));
});

it('does not enable sending without a confirmed dry run', function () {
    $salon = aRebookSalon();
    $user = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    actingAsTenant($user)
        ->get(route('overdue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Overdue/Index')
            ->where('messages_enabled', false)
            ->where('dry_run', null));

    actingAsTenant($user)
        ->post(route('overdue.enable'))
        ->assertRedirect(route('overdue.index'));

    expect(app(RebookMessenger::class)->isEnabled($salon['tenant']->fresh()))->toBeFalse();
});

it('shows who would be contacted before sending can be turned on', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-18 09:00:00');
    $user = User::factory()->create(['tenant_id' => $salon['tenant']->id]);

    actingAsTenant($user)
        ->post(route('overdue.preview-enable'))
        ->assertRedirect();

    actingAsTenant($user)
        ->get(route('overdue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Overdue/Index')
            ->where('messages_enabled', false)
            ->where('dry_run.count', 1)
            ->where('dry_run.messages.0.subject_name', 'Bella')
            ->where('dry_run.messages.0.phone', '+447700900111')
            ->has('dry_run.messages.0.body'));

    actingAsTenant($user)
        ->post(route('overdue.enable'))
        ->assertRedirect(route('overdue.index'));

    expect(app(RebookMessenger::class)->isEnabled($salon['tenant']->fresh()))->toBeTrue();
});

it('sends nothing while messages are off', function () {
    $salon = aRebookSalon(['suggested_interval_days' => 42]);
    aPastVisit($salon, '2026-07-18 09:00:00');

    expect(app(RebookMessenger::class)->sendDue($salon['tenant']))->toBe(0);
});
