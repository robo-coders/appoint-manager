<?php

use App\Models\Tenant;
use App\Services\Billing\SubscriptionState;

it('maps each Stripe subscription status to a distinct local value', function (string $stripe, string $local) {
    $tenant = Tenant::factory()->create([
        'subscription_status' => 'active',
        'plan' => 'monthly',
        'stripe_subscription_id' => 'sub_map',
    ]);

    app(SubscriptionState::class)->apply($tenant, [
        'id' => 'sub_map',
        'status' => $stripe,
        'current_period_end' => now()->addMonth()->timestamp,
        'cancel_at_period_end' => false,
    ]);

    expect($tenant->fresh()->subscription_status)->toBe($local);
})->with([
    ['trialing', 'trial'],
    ['active', 'active'],
    ['past_due', 'past_due'],
    ['unpaid', 'unpaid'],
    ['canceled', 'cancelled'],
    ['incomplete', 'incomplete'],
    ['incomplete_expired', 'incomplete_expired'],
]);

it('keeps cancel_at_period_end independent of an active Stripe status', function () {
    $tenant = Tenant::factory()->create([
        'subscription_status' => 'active',
        'plan' => 'monthly',
        'stripe_subscription_id' => 'sub_cap',
    ]);

    app(SubscriptionState::class)->apply($tenant, [
        'id' => 'sub_cap',
        'status' => 'active',
        'cancel_at_period_end' => true,
        'current_period_end' => now()->addDays(11)->timestamp,
    ]);

    $fresh = $tenant->fresh();

    expect($fresh->subscription_status)->toBe('active')
        ->and($fresh->cancel_at_period_end)->toBeTrue()
        ->and($fresh->subscription_ends_at)->not->toBeNull()
        ->and($fresh->isBillingGated())->toBeFalse();
});

it('does not gate a tenant scheduled to cancel until ends_at has passed', function () {
    $future = Tenant::factory()->create([
        'subscription_status' => 'active',
        'cancel_at_period_end' => true,
        'subscription_ends_at' => now()->addDays(8),
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($future->isBillingGated())->toBeFalse()
        ->and($future->hasAdminWriteAccess())->toBeTrue();

    $past = Tenant::factory()->create([
        'subscription_status' => 'active',
        'cancel_at_period_end' => true,
        'subscription_ends_at' => now()->subDay(),
        'trial_ends_at' => now()->subDay(),
    ]);

    expect($past->isBillingGated())->toBeTrue();
});
