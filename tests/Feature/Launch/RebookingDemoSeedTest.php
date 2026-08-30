<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Services\Rebooking\OverdueSubjects;
use App\Services\Rebooking\RebookMessenger;
use App\Support\TenantContext;

/*
| The seeder exists so the rebooking surface can be looked at and tested on a
| real handset. These assert the two properties that make that possible: the
| overdue list has variety in it, and running the command twice does not double
| anything.
|
| `demo:rebooking` is local-only, so the environment is flipped for the call.
*/

function seedRebookingDemo(array $options = []): int
{
    app()['env'] = 'local';

    $code = test()->artisan('demo:rebooking', array_merge([
        '--slug' => 'seed-test',
        '--phone' => '07700900123',
    ], $options))->run();

    app()['env'] = 'testing';

    return $code;
}

function seedTestTenant(): Tenant
{
    $tenant = Tenant::query()->withoutGlobalScopes()->where('slug', 'seed-test')->firstOrFail();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

it('seeds a salon whose overdue list has genuine variety', function () {
    expect(seedRebookingDemo())->toBe(0);

    $tenant = seedTestTenant();
    $rows = app(OverdueSubjects::class)->forTenant($tenant);
    $subjects = Subject::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();

    // Around twenty, with a real spread rather than everybody overdue.
    expect($subjects)->toBeGreaterThanOrEqual(20)
        ->and($rows->count())->toBeGreaterThan(8)
        ->and($rows->count())->toBeLessThan($subjects)
        // A few just due and a few badly overdue, so the sort order means
        // something when you open the page.
        ->and($rows->where('days_overdue', '<=', 5))->not->toBeEmpty()
        ->and($rows->where('days_overdue', '>=', 40))->not->toBeEmpty();
});

it('seeds the price list from config rather than inventing prices', function () {
    seedRebookingDemo();
    $tenant = seedTestTenant();

    $seeded = Service::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
    $configured = (array) config('verticals.groomer.default_services');

    expect($seeded)->toHaveCount(count($configured));

    foreach ($configured as $row) {
        $service = $seeded->firstWhere('name', $row['name']);

        expect($service)->not->toBeNull()
            ->and($service->price->amount)->toBe($row['price']);
    }
});

it('seeds one subject with the number given on the command line', function () {
    seedRebookingDemo();
    $tenant = seedTestTenant();

    $mine = Customer::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('phone', '+447700900123')
        ->get();

    expect($mine)->toHaveCount(1);

    // Everybody else is on Ofcom's reserved drama range, so nothing seeded here
    // can ring a stranger.
    $others = Customer::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('phone', '!=', '+447700900123')
        ->pluck('phone');

    expect($others)->not->toBeEmpty()
        ->and($others->every(fn (string $phone) => str_starts_with($phone, '+447700900')))->toBeTrue();
});

it('seeds every state the list can show', function () {
    seedRebookingDemo();
    $tenant = seedTestTenant();

    $snoozed = Subject::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('rebook_snoozed_until')->count();
    $stopped = Subject::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('rebook_stopped_at')->count();
    $optedOut = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('sms_opted_out_at')->count();

    expect($snoozed)->toBe(1)
        ->and($stopped)->toBe(1)
        ->and($optedOut)->toBe(1);

    // And the opted-out one is visible on the list with its marker, rather than
    // silently gone.
    $rows = app(OverdueSubjects::class)->forTenant($tenant);
    expect($rows->where('opted_out', true))->toHaveCount(1);
});

it('does not double anything when run twice', function () {
    seedRebookingDemo();
    $tenant = seedTestTenant();

    $before = [
        'customers' => Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        'subjects' => Subject::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        'bookings' => Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        'services' => Service::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
    ];

    seedRebookingDemo();

    expect(Tenant::query()->withoutGlobalScopes()->where('slug', 'seed-test')->count())->toBe(1)
        ->and(Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe($before['customers'])
        ->and(Subject::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe($before['subjects'])
        ->and(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe($before['bookings'])
        ->and(Service::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe($before['services']);
});

it('refuses to run without a test phone number', function () {
    app()['env'] = 'local';
    $code = test()->artisan('demo:rebooking', ['--slug' => 'no-phone', '--phone' => ''])->run();
    app()['env'] = 'testing';

    // Anything else and the one subject that is supposed to be yours is not.
    expect($code)->toBe(1)
        ->and(Tenant::query()->withoutGlobalScopes()->where('slug', 'no-phone')->exists())->toBeFalse();
});

it('refuses to run outside local', function () {
    test()->artisan('demo:rebooking', ['--slug' => 'nope', '--phone' => '07700900123'])->assertFailed();

    expect(Tenant::query()->withoutGlobalScopes()->where('slug', 'nope')->exists())->toBeFalse();
});

it('sends one message to the named subject and none to the other twenty', function () {
    seedRebookingDemo();
    $tenant = seedTestTenant();

    $scout = Subject::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'Scout')
        ->firstOrFail();

    // Sending is off, as it is for a real tenant until she confirms a dry run.
    // --force is what makes a deliberate single test send possible anyway.
    expect(app(RebookMessenger::class)->isEnabled($tenant))->toBeFalse();

    test()->artisan('rebooking:send', [
        '--tenant' => 'seed-test',
        '--subject' => [(string) $scout->id],
        '--ignore-window' => true,
        '--force' => true,
    ])->assertSuccessful();

    $sms = Message::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('channel', 'sms')
        ->get();

    expect($sms)->toHaveCount(1)
        ->and($sms[0]->to)->toBe('+447700900123')
        ->and($sms[0]->subject_id)->toBe($scout->id);
});
