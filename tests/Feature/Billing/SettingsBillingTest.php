<?php

use App\Models\BillingReceipt;
use App\Models\PaymentFailure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\FakeBillingGateway;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Concurrent;

function postBillingWebhook(array $payload): void
{
    test()->call('POST', route('stripe.billing.webhook'), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_billing',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR))->assertOk();
}

function aBillingOwner(array $tenant = []): User
{
    $salon = Tenant::factory()->create(array_merge([
        'timezone' => 'Europe/London',
        'subscription_status' => 'active',
        'plan' => 'monthly',
        'stripe_customer_id' => 'cus_test',
        'stripe_subscription_id' => 'sub_test',
        'card_brand' => 'visa',
        'card_last4' => '4242',
        'card_exp_month' => 4,
        'card_exp_year' => 2028,
        'current_period_end' => now()->addMonth(),
    ], $tenant));

    return User::factory()->for($salon)->owner()->create();
}

it('records a payment_failed webhook with a translated reason', function () {
    FakeBillingGateway::reset();
    FakeBillingGateway::$failureCodes = [
        'ch_fail_1' => 'insufficient_funds',
        'pi_fail_1' => 'insufficient_funds',
    ];

    $tenant = Tenant::factory()->create([
        'stripe_customer_id' => 'cus_fail',
        'subscription_status' => 'active',
    ]);

    postBillingWebhook([
        'id' => 'evt_fail_1',
        'object' => 'event',
        'api_version' => '2026-07-29.dahlia',
        'type' => 'invoice.payment_failed',
        'data' => [
            'object' => [
                'id' => 'in_fail_1',
                'object' => 'invoice',
                'customer' => 'cus_fail',
                'amount_due' => 2900,
                'amount_paid' => 0,
                'currency' => 'gbp',
                'created' => now()->timestamp,
                'next_payment_attempt' => now()->addDays(7)->timestamp,
                'status' => 'open',
                'attempt_count' => 1,
                'attempted' => true,
                'billing_reason' => 'subscription_cycle',
                'collection_method' => 'charge_automatically',
                'parent' => [
                    'type' => 'subscription_details',
                    'subscription_details' => ['subscription' => 'sub_fail'],
                ],
                'payments' => [
                    'object' => 'list',
                    'data' => [[
                        'id' => 'inpay_fail_1',
                        'object' => 'invoice_payment',
                        'status' => 'open',
                        'payment' => [
                            'type' => 'payment_intent',
                            'payment_intent' => 'pi_fail_1',
                            'charge' => 'ch_fail_1',
                        ],
                    ]],
                ],
            ],
        ],
    ]);

    $row = PaymentFailure::query()->first();

    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe($tenant->id)
        ->and($row->stripe_invoice_id)->toBe('in_fail_1')
        ->and($row->failure_reason)->toBe('the bank reported insufficient funds')
        ->and($row->retry_at)->not->toBeNull()
        ->and($row->resolved_at)->toBeNull();

    expect(BillingReceipt::query()->where('stripe_invoice_id', 'in_fail_1')->first())
        ->not->toBeNull()
        ->status->toBe('declined');
});

it('resolves open payment failures when a payment succeeds', function () {
    $tenant = Tenant::factory()->create([
        'stripe_customer_id' => 'cus_ok',
        'subscription_status' => 'past_due',
    ]);

    PaymentFailure::query()->create([
        'tenant_id' => $tenant->id,
        'stripe_invoice_id' => 'in_ok_1',
        'failure_reason' => 'the bank reported insufficient funds',
        'declined_at' => now()->subDay(),
        'retry_at' => now()->addDay(),
    ]);

    postBillingWebhook([
        'id' => 'evt_ok_1',
        'type' => 'invoice.payment_succeeded',
        'data' => [
            'object' => [
                'id' => 'in_ok_1',
                'customer' => 'cus_ok',
                'amount_paid' => 2900,
                'currency' => 'gbp',
                'created' => now()->timestamp,
                'billing_reason' => 'subscription_cycle',
                'subscription' => 'sub_ok',
            ],
        ],
    ]);

    expect(PaymentFailure::query()->whereNull('resolved_at')->count())->toBe(0)
        ->and($tenant->fresh()->subscription_status)->toBe('active')
        ->and(BillingReceipt::query()->where('stripe_invoice_id', 'in_ok_1')->first()?->status)->toBe('paid');
});

it('does not resolve a payment failure for a different invoice', function () {
    $tenant = Tenant::factory()->create([
        'stripe_customer_id' => 'cus_split',
        'subscription_status' => 'past_due',
    ]);

    PaymentFailure::query()->create([
        'tenant_id' => $tenant->id,
        'stripe_invoice_id' => 'in_open_a',
        'failure_reason' => 'the bank reported insufficient funds',
        'declined_at' => now()->subDay(),
        'retry_at' => now()->addDay(),
    ]);

    postBillingWebhook([
        'id' => 'evt_other',
        'type' => 'invoice.payment_succeeded',
        'data' => [
            'object' => [
                'id' => 'in_other_b',
                'customer' => 'cus_split',
                'amount_paid' => 0,
                'currency' => 'gbp',
                'created' => now()->timestamp,
                'billing_reason' => 'manual',
            ],
        ],
    ]);

    expect(PaymentFailure::query()->where('stripe_invoice_id', 'in_open_a')->whereNull('resolved_at')->count())->toBe(1);
});

it('issues unique invoice numbers under concurrent allocation', function () {
    expect(config('database.default'))->toBe('mysql');

    Concurrent::withoutWrappingTransaction(function () {
        $results = Concurrent::run([
            ['type' => 'invoice_number', 'year' => 2026],
            ['type' => 'invoice_number', 'year' => 2026],
            ['type' => 'invoice_number', 'year' => 2026],
            ['type' => 'invoice_number', 'year' => 2026],
        ]);

        $oks = array_values(array_filter($results, fn (array $row) => ($row['ok'] ?? false) === true));
        $numbers = array_column($oks, 'number');

        expect($oks)->toHaveCount(4, 'workers: '.json_encode($results))
            ->and($numbers)->toHaveCount(4)
            ->and(array_unique($numbers))->toHaveCount(4);
    });
})->afterEach(fn () => Concurrent::afterEach());

it('gates non-billing routes when the trial has ended without a card', function () {
    $owner = aBillingOwner([
        'subscription_status' => 'trial',
        'trial_ends_at' => now()->subDay(),
        'stripe_subscription_id' => null,
        'card_last4' => null,
        'plan' => null,
    ]);

    expect($owner->tenant->fresh()->isBillingGated())->toBeTrue();

    actingAsTenant($owner)
        ->get(route('bookings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Billing/AccessRequired'));

    actingAsTenant($owner)
        ->get(route('settings.billing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings/Billing/Index'));
});

it('serves a cached receipt pdf on the second download', function () {
    Storage::fake('local');

    $owner = aBillingOwner();
    $receipt = BillingReceipt::query()->create([
        'tenant_id' => $owner->tenant_id,
        'stripe_invoice_id' => 'in_pdf_1',
        'invoice_number' => 'DD-2026-000001',
        'amount' => 2900,
        'currency' => 'gbp',
        'status' => 'paid',
        'issued_at' => now(),
    ]);

    actingAsTenant($owner)
        ->get(route('settings.billing.receipts.download', $receipt))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $path = $receipt->fresh()->pdf_path;
    expect($path)->not->toBeNull();
    Storage::disk('local')->put($path, 'CACHED-PDF');

    $second = actingAsTenant($owner)
        ->get(route('settings.billing.receipts.download', $receipt));

    $second->assertOk();
    expect($second->streamedContent())->toBe('CACHED-PDF');
});

it('swaps plan through the settings billing route', function () {
    FakeBillingGateway::reset();
    $owner = aBillingOwner(['plan' => 'monthly']);

    actingAsTenant($owner)
        ->post(route('settings.billing.swap'), ['interval' => 'yearly'])
        ->assertRedirect();

    expect($owner->tenant->fresh()->plan)->toBe('yearly');
});

it('cancels at period end and can resume', function () {
    $owner = aBillingOwner([
        'current_period_end' => now()->addDays(12),
    ]);

    actingAsTenant($owner)
        ->post(route('settings.billing.cancel'))
        ->assertRedirect();

    $tenant = $owner->tenant->fresh();
    expect($tenant->cancel_at_period_end)->toBeTrue()
        ->and($tenant->subscription_status)->toBe('active')
        ->and($tenant->subscription_ends_at)->not->toBeNull()
        ->and($tenant->hasAdminWriteAccess())->toBeTrue()
        ->and($tenant->isBillingGated())->toBeFalse();

    actingAsTenant($owner)
        ->post(route('settings.billing.resume'))
        ->assertRedirect();

    $resumed = $owner->tenant->fresh();
    expect($resumed->cancel_at_period_end)->toBeFalse()
        ->and($resumed->subscription_status)->toBe('active')
        ->and($resumed->subscription_ends_at)->toBeNull();
});
