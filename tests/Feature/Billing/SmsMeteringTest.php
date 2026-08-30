<?php

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Jobs\SendSms;
use App\Mail\RebookDueMail;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SmsAllowanceWarning;
use App\Services\Billing\FakeBillingGateway;
use App\Services\Billing\SmsAllowance;
use App\Services\Notifications\Notifier;
use App\Services\Sms\RecordingSmsGateway;
use App\Services\Sms\SmsGateway;
use App\Support\BillingPrice;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

function aMeteredSalon(array $tenant = []): array
{
    $salon = Tenant::factory()->create(array_merge([
        'timezone' => 'Europe/London',
        'email' => 'salon@example.com',
        'sms_cycle_used' => 0,
        'sms_prepaid' => 0,
    ], $tenant));
    app(TenantContext::class)->set($salon);
    $owner = User::factory()->create(['tenant_id' => $salon->id, 'role' => 'owner']);
    $customer = Customer::factory()->create([
        'tenant_id' => $salon->id,
        'email' => 'client@example.com',
        'phone' => '+447700900111',
    ]);
    $subject = Subject::factory()->create([
        'tenant_id' => $salon->id,
        'customer_id' => $customer->id,
        'name' => 'Bella',
    ]);

    return compact('salon', 'owner', 'customer', 'subject');
}

function queueSms(Tenant $tenant, Customer $customer): Message
{
    app(TenantContext::class)->set($tenant);

    $message = new Message;
    $message->forceFill([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'channel' => MessageChannel::Sms,
        'type' => MessageType::RebookDue,
        'to' => $customer->phone,
        'body' => 'Due',
        'status' => MessageStatus::Queued,
    ]);
    $message->save();

    return $message;
}

beforeEach(function () {
    RecordingSmsGateway::$shouldFail = false;
    FakeBillingGateway::reset();
    Notification::fake();
});

it('does not consume allowance for a queued message', function () {
    ['salon' => $salon, 'customer' => $customer] = aMeteredSalon();
    queueSms($salon, $customer);

    expect($salon->fresh()->sms_cycle_used)->toBe(0);
});

it('decrements allowance only after a successful send', function () {
    ['salon' => $salon, 'customer' => $customer] = aMeteredSalon();
    $message = queueSms($salon, $customer);

    SendSms::dispatch($message->id);

    expect($message->fresh()->status)->toBe(MessageStatus::Sent)
        ->and($salon->fresh()->sms_cycle_used)->toBe(1)
        ->and(app(SmsGateway::class)->sent)->toHaveCount(1);
});

it('does not consume allowance when the provider fails', function () {
    ['salon' => $salon, 'customer' => $customer] = aMeteredSalon();
    $message = queueSms($salon, $customer);
    RecordingSmsGateway::$shouldFail = true;

    try {
        SendSms::dispatchSync($message->id);
    } catch (Throwable) {
        // the job is allowed to throw; the point is the count
    }

    expect($salon->fresh()->sms_cycle_used)->toBe(0);
});

it('stops SMS at the included allowance and still sends email', function () {
    Mail::fake();
    ['salon' => $salon, 'customer' => $customer, 'subject' => $subject] = aMeteredSalon([
        'sms_cycle_used' => (int) config('billing.sms_included'),
        'sms_prepaid' => 0,
    ]);

    app(Notifier::class)->rebookDue($salon, $customer, $subject, 'Bella is due.');

    Mail::assertQueued(RebookDueMail::class);
    expect(app(SmsGateway::class)->sent)->toHaveCount(0)
        ->and($salon->fresh()->sms_cycle_used)->toBe((int) config('billing.sms_included'));
});

it('stops SMS at the hard ceiling even when prepaid remains', function () {
    $ceiling = (int) config('billing.sms_hard_ceiling');
    ['salon' => $salon, 'customer' => $customer, 'subject' => $subject] = aMeteredSalon([
        'sms_cycle_used' => $ceiling,
        'sms_prepaid' => 400,
    ]);

    expect(app(SmsAllowance::class)->canSend($salon))->toBeFalse()
        ->and(app(SmsAllowance::class)->blockReason($salon))->toBe('ceiling');

    app(SmsAllowance::class)->applyTopUp($salon);

    expect(app(SmsAllowance::class)->canSend($salon->fresh()))->toBeFalse()
        ->and($salon->fresh()->sms_prepaid)->toBe(400 + (int) config('billing.sms_topup_size'));

    Mail::fake();
    app(Notifier::class)->rebookDue($salon->fresh(), $customer, $subject, 'Bella is due.');
    expect(app(SmsGateway::class)->sent)->toHaveCount(0);
});

it('applies a top-up immediately from the billing webhook', function () {
    ['salon' => $salon] = aMeteredSalon(['sms_prepaid' => 0]);

    $payload = json_encode([
        'id' => 'evt_topup_1',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'customer' => 'cus_topup',
                'metadata' => [
                    'tenant_id' => (string) $salon->id,
                    'kind' => 'sms_topup',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('stripe.billing.webhook'), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_billing',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($salon->fresh()->sms_prepaid)->toBe((int) config('billing.sms_topup_size'))
        ->and($salon->fresh()->subscription_status)->not->toBe('active');
});

it('takes the kill switch on the next send', function () {
    ['salon' => $salon, 'customer' => $customer] = aMeteredSalon();
    $salon->forceFill(['sms_killed_at' => now()])->save();
    $message = queueSms($salon, $customer);

    SendSms::dispatch($message->id);

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($salon->fresh()->sms_cycle_used)->toBe(0)
        ->and(app(SmsGateway::class)->sent)->toHaveCount(0);
});

it('grants credit from super admin without touching Stripe', function () {
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);
    ['salon' => $salon] = aMeteredSalon(['sms_prepaid' => 10]);

    $this->actingAs($admin)
        ->post(route('super-admin.sms.grant', $salon), ['credits' => 50])
        ->assertRedirect();

    expect($salon->fresh()->sms_prepaid)->toBe(60)
        ->and(FakeBillingGateway::$lastTopUpTenantId)->toBeNull();
});

it('persists a trial extension and a trial end', function () {
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);
    ['salon' => $salon] = aMeteredSalon(['trial_ends_at' => now()->addDays(5), 'subscription_status' => 'trial']);

    $this->actingAs($admin)
        ->post(route('super-admin.trial', $salon), ['days' => 60])
        ->assertRedirect();

    expect($salon->fresh()->trialDaysRemaining())->toBe(65)
        ->and($salon->fresh()->hasAdminWriteAccess())->toBeTrue();

    $this->actingAs($admin)
        ->post(route('super-admin.trial', $salon), ['end' => true])
        ->assertRedirect();

    expect($salon->fresh()->trialDaysRemaining())->toBe(0)
        ->and($salon->fresh()->hasAdminWriteAccess())->toBeFalse();
});

it('persists a price override and uses it at checkout', function () {
    $admin = User::factory()->create(['tenant_id' => null, 'is_super_admin' => true]);
    ['salon' => $salon, 'owner' => $owner] = aMeteredSalon();

    $this->actingAs($admin)
        ->post(route('super-admin.price', $salon), ['monthly_price_override_pence' => 2900])
        ->assertRedirect();

    expect($salon->fresh()->monthly_price_override_pence)->toBe(2900)
        ->and(BillingPrice::forTenant($salon->fresh()))->toBe(2900)
        ->and(BillingPrice::listMonthlyPence())->toBe((int) config('billing.monthly_price_pence'));

    actingAsTenant($owner)
        ->post(route('billing.checkout'))
        ->assertRedirect();

    expect(FakeBillingGateway::$lastCheckoutPence)->toBe(2900);
});

it('does not let one tenant consume another tenant\'s allowance', function () {
    ['salon' => $ours, 'customer' => $ourCustomer] = aMeteredSalon();
    ['salon' => $theirs, 'customer' => $theirCustomer] = aMeteredSalon();

    SendSms::dispatch(queueSms($ours, $ourCustomer)->id);

    expect($ours->fresh()->sms_cycle_used)->toBe(1)
        ->and($theirs->fresh()->sms_cycle_used)->toBe(0);

    SendSms::dispatch(queueSms($theirs, $theirCustomer)->id);

    expect($ours->fresh()->sms_cycle_used)->toBe(1)
        ->and($theirs->fresh()->sms_cycle_used)->toBe(1);
});

it('reads the monthly price on the billing page from config', function () {
    ['salon' => $salon, 'owner' => $owner] = aMeteredSalon();

    actingAsTenant($owner)
        ->get(route('billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Billing/Index')
            ->where('billing.monthly_price', BillingPrice::formatPence((int) config('billing.monthly_price_pence')))
            ->missing('billing.yearly_price')
            ->where('sms.included', (int) config('billing.sms_included'))
            ->where('sms.topup_price', BillingPrice::formatPence((int) config('billing.sms_topup_price_pence'))));
});

it('warns at the configured threshold', function () {
    ['salon' => $salon, 'owner' => $owner] = aMeteredSalon([
        'sms_cycle_used' => 159,
        'sms_included_override' => 200,
    ]);
    $customer = Customer::factory()->create(['tenant_id' => $salon->id, 'phone' => '+447700900222']);

    SendSms::dispatch(queueSms($salon, $customer)->id);

    Notification::assertSentTo($owner, SmsAllowanceWarning::class, fn (SmsAllowanceWarning $mail) => $mail->threshold === 80);
});

it('resets included usage on a subscription invoice and keeps prepaid', function () {
    ['salon' => $salon] = aMeteredSalon([
        'sms_cycle_used' => 40,
        'sms_prepaid' => 80,
        'stripe_customer_id' => 'cus_cycle',
    ]);

    $payload = json_encode([
        'id' => 'evt_cycle_1',
        'type' => 'invoice.payment_succeeded',
        'data' => [
            'object' => [
                'customer' => 'cus_cycle',
                'subscription' => 'sub_cycle',
                'billing_reason' => 'subscription_cycle',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('stripe.billing.webhook'), [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'test_billing',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($salon->fresh()->sms_cycle_used)->toBe(0)
        ->and($salon->fresh()->sms_prepaid)->toBe(80);
});
