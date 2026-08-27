<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Subject;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\TenantContext;

/**
 * Outside the test environment `runningUnitTests()` is false, so this reproduces
 * exactly what a queue worker or an artisan command sees.
 */
function asBackgroundProcess(Closure $body): mixed
{
    $previous = app()['env'];
    app()['env'] = 'local';

    try {
        return $body();
    } finally {
        app()['env'] = $previous;
    }
}

it('returns no rows in a background process when no tenant context is set', function () {
    $salon = aSalon();
    Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);
    app(TenantContext::class)->clear();

    $counts = asBackgroundProcess(fn () => [
        'services' => Service::query()->count(),
        'customers' => Customer::query()->count(),
        'bookings' => Booking::query()->count(),
    ]);

    expect($counts['services'])->toBe(0)
        ->and($counts['customers'])->toBe(0)
        ->and($counts['bookings'])->toBe(0);
});

it('still scopes correctly in a background process once a context is set', function () {
    $a = aSalon();
    $b = aSalon();
    app(TenantContext::class)->set($a['tenant']);

    $ids = asBackgroundProcess(fn () => Service::query()->pluck('tenant_id')->unique()->all());

    expect($ids)->toBe([$a['tenant']->id])
        ->and($ids)->not->toContain($b['tenant']->id);
});

it('fails closed for every tenant-owned model', function () {
    $salon = aSalon();
    app(TenantContext::class)->clear();

    /*
     * `User` is in this list now. It used to be the one absentee: it overrode
     * `tenantScopeFailClosed()` to false so that login could find a person
     * before anyone knew their tenant, and that made every `User` read in the
     * application a cross-tenant one. The exemption lives on the auth surface
     * now — `App\Auth\IdentityUserProvider` — so the model itself is like all
     * the others, and this is the assertion that says so.
     */
    $models = [Booking::class, Customer::class, Message::class, Service::class,
        SlotOffer::class, Subject::class, TimeOff::class, User::class, WaitlistEntry::class];

    $counts = asBackgroundProcess(function () use ($models) {
        $out = [];
        foreach ($models as $model) {
            $out[$model] = $model::query()->count();
        }

        return $out;
    });

    expect(array_values($counts))->each->toBe(0);
});
