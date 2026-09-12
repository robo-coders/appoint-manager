<?php

use App\Models\User;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeGateway;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

function aEuroSalon(array $overrides = []): array
{
    return aSalon(array_merge_recursive([
        'tenant' => [
            'currency' => 'EUR',
            'country' => 'IE',
            'slug' => 'dublin-motor-works',
            'name' => 'Dublin Motor Works',
            'type' => 'garage',
        ],
        'service' => ['price' => 5499, 'deposit_amount' => 2000],
    ], $overrides));
}

it('prices the services page in the tenant currency', function () {
    ['tenant' => $tenant] = aEuroSalon();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

    actingAsTenant($owner)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('services.0.price.formatted', '€54.99')
            ->where('services.0.price.currency', 'EUR')
            ->where('services.0.deposit_amount.formatted', '€20.00'));
});

it('prices the public booking page in the tenant currency', function () {
    ['tenant' => $tenant] = aEuroSalon();

    $props = bookingProps($this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent());

    expect($props['tenant']['currency'])->toBe('EUR')
        ->and($props['services'][0]['price']['formatted'])->toBe('€54.99')
        ->and($props['services'][0]['deposit_amount']['formatted'])->toBe('€20.00')
        ->and(json_encode($props['services']))->not->toContain('\u00a3');
});

it('keeps a pound tenant on pounds', function () {
    ['tenant' => $tenant] = aSalon([
        'tenant' => ['slug' => 'willow-street', 'currency' => 'GBP', 'country' => 'GB'],
        'service' => ['price' => 5499, 'deposit_amount' => 2000],
    ]);

    $props = bookingProps($this->get(route('public.booking.show', $tenant->slug))->assertOk()->getContent());

    expect($props['tenant']['currency'])->toBe('GBP')
        ->and($props['services'][0]['price']['formatted'])->toBe('£54.99')
        ->and(json_encode($props['services']))->not->toContain('\u20ac');
});

it('shares the tenant currency with every operator page', function () {
    ['tenant' => $tenant] = aEuroSalon();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

    actingAsTenant($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.currency', 'EUR'));
});

it('sums the dashboard money figures in the tenant currency', function () {
    ['tenant' => $tenant] = aEuroSalon();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

    actingAsTenant($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            expect(json_encode($props))->not->toContain('£');
        });
});

it('creates the Stripe Connect account against the currency the tenant chose', function () {
    ['tenant' => $tenant] = aEuroSalon();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

    actingAsTenant($owner)
        ->post(route('settings.payments.connect'))
        ->assertRedirect();

    $gateway = app(StripeGateway::class);

    expect($gateway)->toBeInstanceOf(FakeStripeGateway::class);

    $account = $gateway->accounts[$tenant->fresh()->stripe_account_id];

    expect($account['default_currency'])->toBe('eur')
        ->and($account['country'])->toBe('IE');
});

it('casts stored pence into the tenant currency when the tenant is in context', function () {
    ['tenant' => $tenant, 'service' => $service] = aEuroSalon();

    app(TenantContext::class)->set($tenant);

    expect($service->refresh()->price->formatted())->toBe('€54.99')
        ->and($service->deposit_amount->formatted())->toBe('€20.00')
        ->and($service->price->currency)->toBe('EUR');
});

it('does not change what DiaryDesk charges the tenant for the platform', function () {
    ['tenant' => $tenant] = aEuroSalon();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

    actingAsTenant($owner)
        ->get(route('settings.billing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('billing.plan.price', '£29.00')
            ->where('billing.plan.period', '/ month')
            ->where('tenant.currency', 'EUR'));
});
