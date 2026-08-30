<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageType;
use App\Enums\Weekday;
use App\Jobs\SendBookingReminder;
use App\Mail\BookingConfirmedMail;
use App\Mail\BookingReminderMail;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Notifications\Notifier;
use App\Services\Sms\SmsGateway;
use App\Services\Stripe\StripeGateway;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

function notifySalon(): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'email' => 'salon@example.com',
        'stripe_account_id' => 'acct_note',
        'stripe_onboarding_complete' => true,
        'country' => 'GB',
    ]);
    app(StripeGateway::class)->completeAccount('acct_note');
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'deposit_amount' => 1000,
        'is_active' => true,
    ]);
    $service->staff()->attach($staff->id);
    AvailabilityRule::factory()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    return compact('tenant', 'staff', 'service');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

it('fires confirmation on webhook success, not on booking creation', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = notifySalon();
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $this->postJson(route('public.booking.store', $tenant->slug), [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $startsAt->toIso8601String(),
        'name' => 'Alex Reed',
        'email' => 'alex@example.com',
        'phone' => '07700900000',
        'subject_name' => 'Willow',
        'subject_attributes' => ['breed' => 'Labrador', 'size' => 'medium'],
    ])->assertCreated();

    Mail::assertNotSent(BookingConfirmedMail::class);

    $booking = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

    test()->postJson('/stripe/webhook', [
        'id' => 'evt_confirm',
        'type' => 'payment_intent.succeeded',
        'account' => $tenant->stripe_account_id,
        'data' => [
            'object' => [
                'id' => $booking->stripe_payment_intent_id,
                'amount_received' => $booking->deposit_at_booking->amount,
                'currency' => 'gbp',
                'metadata' => ['booking_id' => (string) $booking->id],
            ],
        ],
    ], ['Stripe-Signature' => 't=1,v1=test'])->assertOk();

    Mail::assertQueued(BookingConfirmedMail::class, 1);
});

it('schedules a reminder on confirm and cancels it on cancel', function () {
    Bus::fake([SendBookingReminder::class]);
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = notifySalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+447700900000']);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'status' => BookingStatus::Pending,
        'deposit_status' => DepositStatus::Required,
        'source' => BookingSource::Online,
        'stripe_payment_intent_id' => 'pi_rem',
    ]);

    test()->postJson('/stripe/webhook', [
        'id' => 'evt_rem',
        'type' => 'payment_intent.succeeded',
        'account' => $tenant->stripe_account_id,
        'data' => [
            'object' => [
                'id' => 'pi_rem',
                'amount_received' => $booking->deposit_at_booking->amount,
                'currency' => 'gbp',
                'metadata' => ['booking_id' => (string) $booking->id],
            ],
        ],
    ], ['Stripe-Signature' => 't=1,v1=test'])->assertOk();

    Bus::assertDispatched(SendBookingReminder::class);

    $booking->refresh();
    app(BookingService::class)->cancel($booking, 'customer', false);

    expect($booking->fresh()->reminder_cancelled_at)->not->toBeNull();
});

it('does not fire a reminder for a cancelled booking', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = notifySalon();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'email' => 'a@example.com', 'phone' => '+447700900000']);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $booking = Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addHour(),
        'status' => BookingStatus::Cancelled,
        'reminder_cancelled_at' => now(),
        'source' => BookingSource::Online,
    ]);

    (new SendBookingReminder($booking->id))->handle(app(Notifier::class));

    Mail::assertNotSent(BookingReminderMail::class);
});

it('normalises UK and US phone numbers to E.164', function () {
    expect(PhoneNumber::toE164('07700 900000', 'GB'))->toBe('+447700900000')
        ->and(PhoneNumber::toE164('(415) 555-2671', 'US'))->toBe('+14155552671');
});

it('suppresses SMS when the tenant setting is off but still sends email', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = notifySalon();
    $tenant->forceFill([
        'settings' => array_merge($tenant->settings ?? [], ['notifications' => ['sms_enabled' => false]]),
    ])->save();

    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '+447700900000', 'email' => 'a@example.com']);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    app(BookingService::class)->create(
        $tenant->fresh(),
        $service,
        $staff,
        $customer,
        $startsAt,
        BookingSource::Manual,
    );

    Mail::assertQueued(BookingConfirmedMail::class);
    expect(app(SmsGateway::class)->sent)->toBe([])
        ->and(Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('channel', MessageChannel::Sms)->count())->toBe(0)
        ->and(Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('type', MessageType::BookingConfirmed)->where('channel', MessageChannel::Email)->count())->toBeGreaterThan(0);
});

it('does not queue a confirmation email when the client has no address', function () {
    Mail::fake();
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = notifySalon();
    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => null,
        'phone' => '+447700900000',
    ]);
    $startsAt = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    app(BookingService::class)->create(
        $tenant->fresh(),
        $service,
        $staff,
        $customer,
        $startsAt,
        BookingSource::Manual,
    );

    Mail::assertNotQueued(BookingConfirmedMail::class);
    expect(Message::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', MessageType::BookingConfirmed)
        ->where('channel', MessageChannel::Email)
        ->count())->toBe(0);
});
