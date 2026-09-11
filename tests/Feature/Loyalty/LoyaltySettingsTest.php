<?php

use App\Enums\LoyaltyCardStatus;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-03 08:00:00', 'Europe/London'));
});

function anOwnerOf(array $salon): User
{
    return User::factory()->create(['tenant_id' => $salon['tenant']->id]);
}

/** @return array{0: array<string, mixed>, 1: User} */
function aSchemePayload(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'name' => 'Groom card',
        'sessions_required' => 6,
        'reward' => 'A free full groom',
        'eligible_service_id' => null,
        'auto_stamp' => true,
        'auto_enrol' => true,
        'auto_apply_reward' => true,
        'show_visit_date' => true,
    ], $overrides);
}

it('saves the scope and all four switches', function () {
    $salon = aSalon();
    $owner = anOwnerOf($salon);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), aSchemePayload([
            'eligible_service_id' => $salon['service']->id,
            'auto_stamp' => false,
            'auto_enrol' => false,
            'auto_apply_reward' => false,
            'show_visit_date' => false,
        ]))
        ->assertSessionHasNoErrors();

    $package = LoyaltyPackage::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole();

    expect($package->eligible_service_id)->toBe($salon['service']->id)
        ->and($package->auto_stamp)->toBeFalse()
        ->and($package->auto_enrol)->toBeFalse()
        ->and($package->auto_apply_reward)->toBeFalse()
        ->and($package->show_visit_date)->toBeFalse();
});

it('reads null as all services', function () {
    $salon = aSalon();

    $this->actingAs(anOwnerOf($salon))
        ->patch(route('settings.loyalty.update'), aSchemePayload())
        ->assertSessionHasNoErrors();

    expect(LoyaltyPackage::withoutGlobalScopes()->sole()->eligible_service_id)->toBeNull();
});

it('leaves a switch it was not sent exactly as it was', function () {
    $salon = aSalon();
    $owner = anOwnerOf($salon);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), aSchemePayload(['auto_enrol' => false]))
        ->assertSessionHasNoErrors();

    $payload = aSchemePayload();
    unset($payload['auto_enrol']);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), $payload)
        ->assertSessionHasNoErrors();

    expect(LoyaltyPackage::withoutGlobalScopes()->sole()->auto_enrol)->toBeFalse();
});

it('refuses a card longer than the configured ceiling', function () {
    $salon = aSalon();

    $this->actingAs(anOwnerOf($salon))
        ->patch(route('settings.loyalty.update'), aSchemePayload([
            'sessions_required' => config('loyalty.max_visits_required') + 1,
        ]))
        ->assertSessionHasErrors(['sessions_required']);
});

it('accepts a card exactly at each end of the configured range', function () {
    $salon = aSalon();
    $owner = anOwnerOf($salon);

    foreach ([config('loyalty.min_visits_required'), config('loyalty.max_visits_required')] as $count) {
        $this->actingAs($owner)
            ->patch(route('settings.loyalty.update'), aSchemePayload(['sessions_required' => $count]))
            ->assertSessionHasNoErrors();
    }

    expect(LoyaltyPackage::withoutGlobalScopes()->sole()->sessions_required)
        ->toBe((int) config('loyalty.max_visits_required'));
});

it('refuses a reward longer than the configured length', function () {
    $salon = aSalon();

    $this->actingAs(anOwnerOf($salon))
        ->patch(route('settings.loyalty.update'), aSchemePayload([
            'reward' => str_repeat('a', (int) config('loyalty.max_reward_description_length') + 1),
        ]))
        ->assertSessionHasErrors(['reward']);
});

it('refuses a service belonging to another salon', function () {
    $salon = aSalon();
    $other = aSalon();

    $this->actingAs(anOwnerOf($salon))
        ->patch(route('settings.loyalty.update'), aSchemePayload([
            'eligible_service_id' => $other['service']->id,
        ]))
        ->assertSessionHasErrors(['eligible_service_id']);

    expect(LoyaltyPackage::withoutGlobalScopes()->count())->toBe(0);
});

it('offers only this salon services on the screen', function () {
    $salon = aSalon();
    $other = aSalon();

    Service::factory()->create(['tenant_id' => $other['tenant']->id, 'name' => 'Somebody else clip']);

    $this->actingAs(anOwnerOf($salon))
        ->get(route('settings.loyalty.edit'))
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Loyalty')
            ->where('services', fn ($services) => collect($services)->pluck('label')->doesntContain('Somebody else clip')));
});

it('never writes over another salon scheme', function () {
    $salon = aSalon();
    $other = aSalon();

    $theirs = LoyaltyPackage::factory()->create([
        'tenant_id' => $other['tenant']->id,
        'name' => 'Their card',
        'sessions_required' => 4,
    ]);

    $this->actingAs(anOwnerOf($salon))
        ->patch(route('settings.loyalty.update'), aSchemePayload())
        ->assertSessionHasNoErrors();

    expect($theirs->fresh()->name)->toBe('Their card')
        ->and($theirs->fresh()->sessions_required)->toBe(4)
        ->and(LoyaltyPackage::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->sole()->name)
        ->toBe('Groom card');
});

it('shows a salon its own scheme and not anybody else', function () {
    $salon = aSalon();
    $other = aSalon();

    LoyaltyPackage::factory()->create(['tenant_id' => $other['tenant']->id, 'name' => 'Their card']);
    LoyaltyPackage::factory()->create(['tenant_id' => $salon['tenant']->id, 'name' => 'Our card']);

    $this->actingAs(anOwnerOf($salon))
        ->get(route('settings.loyalty.edit'))
        ->assertInertia(fn ($page) => $page->where('loyalty.name', 'Our card'));
});

it('completes the cards that a shorter scheme has already qualified', function () {
    $salon = aSalon();
    $owner = anOwnerOf($salon);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), aSchemePayload(['sessions_required' => 8]))
        ->assertSessionHasNoErrors();

    $package = LoyaltyPackage::withoutGlobalScopes()->sole();

    $customer = new Customer;
    $customer->forceFill([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Alex Reed',
        'email' => 'alex@example.com',
        'phone' => '+447700900000',
    ])->save();

    $card = LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => $package->id,
        'stamps_used' => 6,
    ]);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), aSchemePayload(['sessions_required' => 5]))
        ->assertSessionHasNoErrors();

    expect($card->fresh()->status)->toBe(LoyaltyCardStatus::StampedOut)
        ->and($card->fresh()->stamps_used)->toBe(5)
        ->and($card->fresh()->completed_at)->not->toBeNull();
});

it('sends the screen what it needs to warn about a shorter card', function () {
    $salon = aSalon();
    $owner = anOwnerOf($salon);

    $this->actingAs($owner)
        ->patch(route('settings.loyalty.update'), aSchemePayload(['sessions_required' => 8]))
        ->assertSessionHasNoErrors();

    $customer = new Customer;
    $customer->forceFill([
        'tenant_id' => $salon['tenant']->id,
        'name' => 'Alex Reed',
        'email' => 'alex@example.com',
        'phone' => '+447700900000',
    ])->save();

    LoyaltyEnrolment::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $customer->id,
        'loyalty_package_id' => LoyaltyPackage::withoutGlobalScopes()->sole()->id,
        'stamps_used' => 6,
    ]);

    $this->actingAs($owner)
        ->get(route('settings.loyalty.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('progress.6', 1)
            ->where('limits.min_visits', (int) config('loyalty.min_visits_required'))
            ->where('limits.max_visits', (int) config('loyalty.max_visits_required'))
            ->where('limits.max_reward_length', (int) config('loyalty.max_reward_description_length')));
});
