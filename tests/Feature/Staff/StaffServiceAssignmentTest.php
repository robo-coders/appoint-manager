<?php

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Availability\AvailabilityEngine;
use App\Services\Booking\BookingReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Which services each member of staff performs.
 *
 * The link itself is not new — `service_user` has been the availability
 * engine's only answer to "who can do this" since the public booking page
 * shipped, and the Services screen has always been able to write it from the
 * service's side. What was missing was the other direction: an operator adding
 * a colleague had no way to say what that colleague does, so every new person
 * arrived attached to nothing and every service they should have covered was
 * silently unbookable online.
 *
 * Two rules in here are the ones worth breaking if they regress:
 *
 *   - **A new person gets everything.** The default is on the *creation*
 *     path, not only on the form, so a staff member added by any route is
 *     bookable at once.
 *   - **A sync only ever touches active services.** The checklist shows active
 *     services only, so a blind `sync()` of what it submits would quietly drop
 *     a link to a service that happened to be hidden that afternoon.
 */
function aSalonWithThreeServices(): array
{
    $owner = User::factory()->create(['name' => 'Ada Owner']);
    $tenant = $owner->tenant;

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'groom' => Service::factory()->for($tenant)->create(['name' => 'Full groom', 'sort_order' => 0]),
        'trim' => Service::factory()->for($tenant)->create(['name' => 'Puppy trim', 'sort_order' => 1]),
        'hidden' => Service::factory()->for($tenant)->create(['name' => 'Hand strip', 'is_active' => false, 'sort_order' => 2]),
    ];
}

/** The pivot, read without any scope at all — the table, not a relationship's opinion of it. */
function linkedServiceIds(User $staff): array
{
    return DB::table('service_user')
        ->where('user_id', $staff->id)
        ->pluck('service_id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

describe('a new staff member', function () {
    it('is linked to every active service and to no inactive one', function () {
        ['owner' => $owner, 'groom' => $groom, 'trim' => $trim] = aSalonWithThreeServices();

        actingAsTenant($owner)
            ->post(route('staff.store'), ['name' => 'Sam Reed', 'email' => 'sam@example.com'])
            ->assertRedirect(route('staff.index'))
            ->assertSessionHasNoErrors();

        $created = User::withoutGlobalScopes()->where('email', 'sam@example.com')->sole();

        expect(linkedServiceIds($created))->toEqualCanonicalizing([$groom->id, $trim->id]);
    });

    /*
     * The default lives on the creation path rather than in the form's initial
     * state, which is what this asserts: no `service_ids` in the payload at all
     * and the links are still written.
     */
    it('takes the default even when the request never mentions services', function () {
        ['owner' => $owner, 'groom' => $groom, 'trim' => $trim] = aSalonWithThreeServices();

        actingAsTenant($owner)->post(route('staff.store'), [
            'name' => 'Sam Reed',
            'email' => 'sam@example.com',
            'is_bookable' => true,
            'can_see_customer_contacts' => false,
        ])->assertSessionHasNoErrors();

        $created = User::withoutGlobalScopes()->where('email', 'sam@example.com')->sole();

        expect(linkedServiceIds($created))->toEqualCanonicalizing([$groom->id, $trim->id])
            ->and($created->can_see_customer_contacts)->toBeFalse();
    });

    it('honours a narrower list when the operator sends one', function () {
        ['owner' => $owner, 'trim' => $trim] = aSalonWithThreeServices();

        actingAsTenant($owner)->post(route('staff.store'), [
            'name' => 'Sam Reed',
            'email' => 'sam@example.com',
            'service_ids' => [$trim->id],
        ])->assertSessionHasNoErrors();

        $created = User::withoutGlobalScopes()->where('email', 'sam@example.com')->sole();

        expect(linkedServiceIds($created))->toBe([$trim->id]);
    });

    it('does not fall over in a salon with no services yet', function () {
        $owner = User::factory()->create();

        actingAsTenant($owner)
            ->post(route('staff.store'), ['name' => 'Sam Reed', 'email' => 'sam@example.com'])
            ->assertRedirect(route('staff.index'))
            ->assertSessionHasNoErrors();

        $created = User::withoutGlobalScopes()->where('email', 'sam@example.com')->sole();

        expect(linkedServiceIds($created))->toBe([]);
    });
});

describe('editing a staff member', function () {
    it('adds and removes links in one save', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom, 'trim' => $trim] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create();
        $staff->services()->attach([$groom->id]);

        actingAsTenant($owner)
            ->patch(route('staff.update', $staff), ['service_ids' => [$trim->id]])
            ->assertRedirect(route('staff.index'))
            ->assertSessionHasNoErrors();

        expect(linkedServiceIds($staff))->toBe([$trim->id]);
    });

    it('refuses a service id belonging to another salon and writes nothing', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create();
        $staff->services()->attach([$groom->id]);

        $stranger = Service::factory()->for(Tenant::factory()->create())->create(['name' => 'Somebody else']);

        actingAsTenant($owner)
            ->patch(route('staff.update', $staff), ['service_ids' => [$stranger->id]])
            ->assertSessionHasErrors('service_ids.0');

        expect(linkedServiceIds($staff))->toBe([$groom->id]);
    });

    /*
     * The checklist only lists active services, so a straight `sync()` of what
     * it submits would detach `hidden` — and nobody touched it. Hiding a service
     * for a fortnight would quietly unpick every link it had.
     */
    it('leaves a link to an inactive service alone', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom, 'trim' => $trim, 'hidden' => $hidden] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create();
        $staff->services()->attach([$groom->id, $hidden->id]);

        actingAsTenant($owner)
            ->patch(route('staff.update', $staff), ['service_ids' => [$trim->id]])
            ->assertSessionHasNoErrors();

        expect(linkedServiceIds($staff))->toEqualCanonicalizing([$trim->id, $hidden->id]);
    });

    it('leaves a link to a deleted service alone rather than erroring', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom, 'trim' => $trim] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create();
        $staff->services()->attach([$groom->id, $trim->id]);

        $trim->delete();

        actingAsTenant($owner)
            ->patch(route('staff.update', $staff), ['service_ids' => [$groom->id]])
            ->assertSessionHasNoErrors();

        expect(linkedServiceIds($staff))->toEqualCanonicalizing([$groom->id, $trim->id]);
    });

    /*
     * `patchJson`, not `patch`. An empty array does not survive form encoding —
     * it vanishes from the payload, so the server would read "not submitted"
     * rather than "submitted empty" and change nothing. Inertia's `useForm`
     * sends JSON, which is what this reproduces.
     */
    it('allows every service to be unchecked', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom, 'trim' => $trim] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create();
        $staff->services()->attach([$groom->id, $trim->id]);

        actingAsTenant($owner)
            ->patchJson(route('staff.update', $staff), ['service_ids' => []])
            ->assertRedirect(route('staff.index'));

        expect(linkedServiceIds($staff))->toBe([]);
    });

    it('leaves the rest of the record alone when only services are sent', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'trim' => $trim] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create(['name' => 'Sam Reed', 'is_bookable' => true]);

        actingAsTenant($owner)
            ->patch(route('staff.update', $staff), ['service_ids' => [$trim->id]])
            ->assertSessionHasNoErrors();

        $staff->refresh();

        expect($staff->name)->toBe('Sam Reed')
            ->and($staff->is_bookable)->toBeTrue();
    });
});

describe('the staff screen', function () {
    it('offers the active services and says which are already linked', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom, 'trim' => $trim, 'hidden' => $hidden] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create(['name' => 'Sam Reed']);
        $staff->services()->attach([$trim->id, $hidden->id]);

        actingAsTenant($owner)
            ->get(route('staff.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Index')
                ->where('services', [
                    ['id' => $groom->id, 'name' => 'Full groom'],
                    ['id' => $trim->id, 'name' => 'Puppy trim'],
                ]));
    });

    it('reports only the active links on each row', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'trim' => $trim, 'hidden' => $hidden] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create(['name' => 'Sam Reed']);
        $staff->services()->attach([$trim->id, $hidden->id]);

        $page = null;

        actingAsTenant($owner)
            ->get(route('staff.index'))
            ->assertInertia(function (AssertableInertia $inertia) use (&$page) {
                $page = $inertia;
            });

        $rows = collect($page->toArray()['props']['staff'])->keyBy('name');

        expect($rows['Sam Reed']['service_ids'])->toBe([$trim->id])
            ->and($rows['Ada Owner']['service_ids'])->toBe([]);
    });

    it('sends an empty list when the salon has no services', function () {
        $owner = User::factory()->create();

        actingAsTenant($owner)
            ->get(route('staff.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('services', []));
    });
});

describe('the services list count', function () {
    it('counts the linked staff who are still active', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom, 'trim' => $trim] = aSalonWithThreeServices();

        $working = User::factory()->for($tenant)->staff()->create();
        $alsoWorking = User::factory()->for($tenant)->staff()->create();
        $gone = User::factory()->for($tenant)->staff()->inactive()->create();

        $groom->staff()->attach([$owner->id, $working->id, $alsoWorking->id, $gone->id]);

        $page = null;

        actingAsTenant($owner)
            ->get(route('services.index'))
            ->assertInertia(function (AssertableInertia $inertia) use (&$page) {
                $page = $inertia;
            });

        $services = collect($page->toArray()['props']['services'])->keyBy('id');

        expect($services[$groom->id]['staff_count'])->toBe(3)
            ->and($services[$trim->id]['staff_count'])->toBe(0);
    });

    it('follows a link written from the staff side', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create();

        actingAsTenant($owner)
            ->patch(route('staff.update', $staff), ['service_ids' => [$groom->id]])
            ->assertSessionHasNoErrors();

        $page = null;

        $this->get(route('services.index'))->assertInertia(function (AssertableInertia $inertia) use (&$page) {
            $page = $inertia;
        });

        $services = collect($page->toArray()['props']['services'])->keyBy('id');

        expect($services[$groom->id]['staff_count'])->toBe(1);
    });
});

describe('online availability', function () {
    /*
     * Two services, same salon, same hours, same staff member — and only one of
     * them on `service_user`. One engine call each, so the per-tenant
     * availability cache cannot be what makes the second answer empty.
     */
    it('offers slots only for the service the staff member is linked to', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

        $salon = aSalon();
        $unlinked = Service::factory()->for($salon['tenant'])->create([
            'name' => 'Nobody does this',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
        ]);

        $from = CarbonImmutable::parse('2026-03-10 00:00:00', 'Europe/London')->utc();
        $to = $from->addDay();

        $engine = app(AvailabilityEngine::class);

        expect($engine->slotsFor($salon['tenant'], $salon['service'], $from, $to))->not->toBeEmpty()
            ->and($engine->slotsFor($salon['tenant'], $unlinked, $from, $to))->toBeEmpty();
    });

    it('names the linked staff on each slot', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

        $salon = aSalon();

        $from = CarbonImmutable::parse('2026-03-10 00:00:00', 'Europe/London')->utc();
        $slots = app(AvailabilityEngine::class)->slotsFor($salon['tenant'], $salon['service'], $from, $from->addDay());

        expect($slots->first()?->staffIds)->toBe([$salon['staff']->id]);
    });

    /*
     * The "not bookable online" check reads the same table, so a link written
     * from the staff edit form is the one it answers from.
     */
    it('answers the setup check from the same table the staff form writes', function () {
        ['owner' => $owner, 'tenant' => $tenant, 'groom' => $groom] = aSalonWithThreeServices();
        $staff = User::factory()->for($tenant)->staff()->create(['is_bookable' => true]);

        expect(BookingReadiness::hasStaffForService($tenant, $groom))->toBeFalse();

        actingAsTenant($owner)
            ->patch(route('staff.update', $staff), ['service_ids' => [$groom->id]])
            ->assertSessionHasNoErrors();

        expect(BookingReadiness::hasStaffForService($tenant, $groom))->toBeTrue();

        actingAsTenant($owner)
            ->patchJson(route('staff.update', $staff), ['service_ids' => []])
            ->assertRedirect(route('staff.index'));

        expect(BookingReadiness::hasStaffForService($tenant, $groom))->toBeFalse();
    });

    /*
     * Unchecking a service is a statement about *new* availability. A booking
     * already in the diary is an appointment somebody has been told about, and
     * this screen does not get to cancel it.
     */
    it('leaves an existing future booking untouched when the link is removed', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));

        $salon = aSalon();
        $booking = aSandboxBooking($salon, '2026-03-10 10:00:00');
        $status = $booking->status;

        $owner = $salon['staff'];

        actingAsTenant($owner)
            ->patchJson(route('staff.update', $owner), ['service_ids' => []])
            ->assertRedirect(route('staff.index'));

        $booking->refresh();

        expect($booking->status)->toBe($status)
            ->and($booking->staff_id)->toBe($owner->id)
            ->and($booking->service_id)->toBe($salon['service']->id);
    });
});
