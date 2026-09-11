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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('keeps one entry when staff add the same customer twice', function () {
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
        ->assertRedirect(route('waitlist.index'))
        ->assertSessionHas('toast', 'Added to the waitlist.');

    actingAsTenant($owner)->withoutExceptionHandling()
        ->post(route('waitlist.store'), $payload)
        ->assertRedirect(route('waitlist.index'))
        ->assertSessionHas('toast', fn (string $toast) => str_contains($toast, 'already on the waitlist'));

    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();
    $entries = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();

    expect($customers)->toHaveCount(1)
        ->and($entries)->toHaveCount(1)
        ->and($entries->sole()->customer_id)->toBe($customers->sole()->id)
        ->and($entries->sole()->service_id)->toBe($salon['service']->id)
        ->and($entries->sole()->is_active)->toBeTrue();
});

it('keeps one entry when the public page is used twice by one person', function () {
    $salon = aSalon();

    $payload = [
        'service_id' => $salon['service']->id,
        'name' => 'Sam L',
        'email' => 'sam@example.com',
        'phone' => '07700900111',
    ];

    $first = $this->postJson(route('public.booking.waitlist', $salon['tenant']->slug), $payload)
        ->assertCreated()
        ->assertJsonPath('already_waiting', false)
        ->json('id');

    $second = $this->postJson(route('public.booking.waitlist', $salon['tenant']->slug), $payload)
        ->assertOk()
        ->assertJsonPath('already_waiting', true)
        ->assertJsonPath('message', 'You’re already on the waitlist for this service. We’ll text you as soon as a slot opens.')
        ->json('id');

    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();
    $entries = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();

    expect($second)->toBe($first)
        ->and($customers)->toHaveCount(1)
        ->and($entries)->toHaveCount(1)
        ->and($entries->sole()->customer_id)->toBe($customers->sole()->id);
});

it('treats a different time preference as the same standing request', function () {
    $salon = aSalon();
    $owner = User::factory()->for($salon['tenant'])->owner()->create();

    $payload = [
        'name' => 'Sam L',
        'email' => 'sam@example.com',
        'phone' => '07700900111',
        'service_id' => $salon['service']->id,
    ];

    actingAsTenant($owner)->withoutExceptionHandling()
        ->post(route('waitlist.store'), $payload + ['preferred_times' => PreferredTime::Morning->value])
        ->assertRedirect(route('waitlist.index'));

    actingAsTenant($owner)->withoutExceptionHandling()
        ->post(route('waitlist.store'), $payload + ['preferred_times' => PreferredTime::Afternoon->value])
        ->assertRedirect(route('waitlist.index'));

    $entries = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->sole()->preferred_times)->toBe(PreferredTime::Morning);
});

it('spreads the offer batch over distinct customers', function () {
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
    $this->postJson(route('public.booking.waitlist', $tenant->slug), $payload)->assertOk();

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

    expect($ranked)->toHaveCount(3)
        ->and($ranked->pluck('customer_id')->unique())->toHaveCount(3)
        ->and($sent)->toBe(2)
        ->and($offers)->toHaveCount(2)
        ->and($offered->pluck('customer_id')->unique())->toHaveCount(2)
        ->and($offered->pluck('customer_id')->all())->toContain($sam->id)
        ->and($texts)->toHaveCount(2)
        ->and($texts->pluck('customer_id')->unique())->toHaveCount(2)
        ->and($texts->where('customer_id', $sam->id))->toHaveCount(1)
        ->and($offers->pluck('waitlist_entry_id')->contains($others->last()->id))->toBeFalse();
});

it('refuses a duplicate active entry at the database', function () {
    $salon = aSalon();
    $customer = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

    WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'service_id' => $salon['service']->id,
        'preferred_times' => PreferredTime::Any,
        'is_active' => true,
    ]);

    $row = [
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'service_id' => $salon['service']->id,
        'preferred_days' => json_encode([]),
        'preferred_times' => PreferredTime::Morning->value,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => DB::table('waitlist_entries')->insert($row))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('waitlist_entries')->insert(['is_active' => false] + $row);

    expect(WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(2);
});

it('lets a customer rejoin once their entry has been claimed', function () {
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

    WaitlistEntry::withoutGlobalScopes()->findOrFail($first)->forceFill(['is_active' => false])->save();

    $second = $this->postJson(route('public.booking.waitlist', $salon['tenant']->slug), $payload)
        ->assertCreated()
        ->assertJsonPath('already_waiting', false)
        ->json('id');

    $entries = WaitlistEntry::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->orderBy('id')
        ->get();

    expect($second)->not->toBe($first)
        ->and($entries)->toHaveCount(2)
        ->and($entries->pluck('is_active')->all())->toBe([false, true]);
});
