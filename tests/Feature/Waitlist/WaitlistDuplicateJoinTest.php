<?php

use App\Enums\MessageType;
use App\Enums\PreferredTime;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SlotOffer;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistOfferer;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Joining the waitlist twice
|--------------------------------------------------------------------------
|
| Verification only — these tests assert what the code does today, not what it
| ought to do. The audit claims neither join path dedups, so the same person can
| sit on one service's waitlist more than once, take more than one place in an
| offer batch sized for distinct customers, and be texted once per place.
|
| `CustomerResolver` (and the public controller's `findOrCreateCustomer`) makes
| both joins resolve to the *same* customer row — that part is fixed and
| `WaitlistCustomerResolutionTest` covers it. What neither path does is look for
| an existing waitlist entry for that customer and service before inserting, and
| `waitlist_entries` carries no unique index to stop it either.
|
| When a fix lands, the three tests below are the ones to flip: the first two
| should end with one entry each, and the third should spread the batch over
| distinct customers.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('creates a second waitlist entry when staff add the same customer twice', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();

    $payload = [
        'name' => 'Sam L',
        'email' => 'sam@example.com',
        'phone' => '07700900111',
        'service_id' => $salon['service']->id,
    ];

    actingAsTenant($owner)->withoutExceptionHandling()
        ->post(route('waitlist.store'), $payload)
        ->assertRedirect(route('waitlist.index'));

    actingAsTenant($owner)->withoutExceptionHandling()
        ->post(route('waitlist.store'), $payload)
        ->assertRedirect(route('waitlist.index'));

    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();
    $entries = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();

    expect($customers)->toHaveCount(1)
        ->and($entries)->toHaveCount(2)
        ->and($entries->pluck('customer_id')->unique()->all())->toBe([$customers->sole()->id])
        ->and($entries->pluck('service_id')->unique()->all())->toBe([$salon['service']->id])
        ->and($entries->pluck('is_active')->all())->toBe([true, true]);
});

it('creates a second waitlist entry when the public page is used twice by one person', function () {
    $salon = aSalon();

    $payload = [
        'service_id' => $salon['service']->id,
        'name' => 'Sam L',
        'email' => 'sam@example.com',
        'phone' => '07700900111',
    ];

    $first = $this->postJson(route('public.booking.waitlist', $salon['tenant']->slug), $payload)
        ->assertCreated()
        ->json('id');

    $second = $this->postJson(route('public.booking.waitlist', $salon['tenant']->slug), $payload)
        ->assertCreated()
        ->json('id');

    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();
    $entries = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();

    expect($second)->not->toBe($first)
        ->and($customers)->toHaveCount(1)
        ->and($entries)->toHaveCount(2)
        ->and($entries->pluck('customer_id')->unique()->all())->toBe([$customers->sole()->id]);
});

/*
 * The downstream half of the claim. The batch is set to two so the slot has
 * exactly two places to give away, and three distinct people want it — except
 * the first of them joined twice, which is enough to take both places before
 * the other two are reached.
 */
it('lets one duplicated customer take the whole offer batch and be texted twice', function () {
    $salon = aSalon();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = $salon;
    $tenant->forceFill(['settings' => ['waitlist' => ['offer_batch_size' => 2]]])->save();

    $payload = [
        'service_id' => $service->id,
        'name' => 'Sam L',
        'email' => 'sam@example.com',
        'phone' => '07700900111',
    ];

    $this->postJson(route('public.booking.waitlist', $tenant->slug), $payload)->assertCreated();
    $this->postJson(route('public.booking.waitlist', $tenant->slug), $payload)->assertCreated();

    $sam = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    $others = collect(['ada@example.com', 'ben@example.com'])->map(function (string $email) use ($tenant, $service) {
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'email' => $email]);

        return WaitlistEntry::factory()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'preferred_days' => [],
            'preferred_times' => PreferredTime::Any,
            'is_active' => true,
        ]);
    });

    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    // All four entries match the slot, so the two who miss out are squeezed out
    // by the duplicate rather than filtered away on day or time.
    $ranked = app(WaitlistOfferer::class)->rankedMatches($tenant, $service, $starts);

    $sent = app(WaitlistOfferer::class)->offer($tenant, $service, $staff, $starts, $starts->addHour());

    $offers = SlotOffer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
    $offered = WaitlistEntry::withoutGlobalScopes()
        ->whereIn('id', $offers->pluck('waitlist_entry_id'))
        ->get();

    $texts = Message::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', MessageType::WaitlistOffer->value)
        ->get();

    expect($ranked)->toHaveCount(4)
        ->and($ranked->pluck('customer_id')->unique())->toHaveCount(3)
        ->and($sent)->toBe(2)
        ->and($offers)->toHaveCount(2)
        ->and($offered->pluck('customer_id')->all())->toBe([$sam->id, $sam->id])
        ->and($offered->pluck('customer_id')->unique())->toHaveCount(1)
        ->and($texts)->toHaveCount(2)
        ->and($texts->pluck('customer_id')->unique()->all())->toBe([$sam->id])
        ->and($texts->pluck('to')->unique()->all())->toBe([$sam->phone])
        ->and($offers->pluck('waitlist_entry_id')->intersect($others->pluck('id'))->all())->toBe([]);
});
