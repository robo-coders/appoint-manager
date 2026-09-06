<?php

use App\BetaSandbox\SampleData;
use App\Enums\BookingStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageType;
use App\Enums\SlotOfferStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\SlotOffer;
use App\Models\WaitlistEntry;
use App\Sandbox\DateJump;
use App\Sandbox\NoShowSimulator;
use App\Sandbox\ReminderTrigger;
use App\Sandbox\SandboxState;
use App\Sandbox\WaitlistSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London'));
    Mail::fake();
});

it('renders the toolkit props on the sandbox screen', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])
        ->get(route('beta-sandbox.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Sandbox/Index')
            ->has('sizes', 3)
            ->has('summary')
            ->has('outbox')
            ->has('candidates')
            ->where('flaky_network', false)
            ->where('jump_min', '2026-09-09'));
});

it('jumps the shop forward to a chosen date and runs the same automations', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-20 10:00:00');
    $was = $booking->starts_at;

    $result = app(DateJump::class)->run($salon['tenant'], '2026-09-15');

    expect($result['days'])->toBe(7);
    expect($booking->fresh()->starts_at->toDateTimeString())
        ->toBe($was->copy()->subDays(7)->toDateTimeString());
});

it('refuses a jump to today or earlier', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.jump'), ['date' => '2026-09-08'])
        ->assertRedirect()
        ->assertSessionHasErrors('sandbox');
});

it('marks a future appointment as a no-show through the real pathway', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-12 10:00:00');

    $marked = app(NoShowSimulator::class)->mark($salon['tenant'], $booking->id, $salon['staff']);

    expect($marked->status)->toBe(BookingStatus::NoShow);
    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('auto-picks the next upcoming appointment when none is named', function () {
    $salon = aBetaSalon();
    $later = aSandboxBooking($salon, '2026-09-18 10:00:00');
    $next = $later->replicate(['public_token']);
    $next->forceFill([
        'starts_at' => CarbonImmutable::parse('2026-09-10 11:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'Europe/London')->utc(),
    ])->save();

    app(NoShowSimulator::class)->mark($salon['tenant'], null, $salon['staff']);

    expect($next->fresh()->status)->toBe(BookingStatus::NoShow);
    expect($later->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('frees a booked slot and offers it to the waitlist', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-12 10:00:00');
    $waiter = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

    WaitlistEntry::factory()->create([
        'tenant_id' => $salon['tenant']->id,
        'customer_id' => $waiter->id,
        'service_id' => $salon['service']->id,
        'is_active' => true,
        'preferred_days' => [],
        'preferred_times' => 'any',
    ]);

    $result = app(WaitlistSimulator::class)->freeSlot($salon['tenant']);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
    expect($result['offered'])->toBeGreaterThan(0);
    expect(SlotOffer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBeGreaterThan(0);
});

it('expires the current waitlist offer and can roll it on', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-12 10:00:00');
    $first = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);
    $second = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

    foreach ([$first, $second] as $waiter) {
        WaitlistEntry::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'customer_id' => $waiter->id,
            'service_id' => $salon['service']->id,
            'is_active' => true,
            'preferred_days' => [],
            'preferred_times' => 'any',
        ]);
    }

    app(WaitlistSimulator::class)->freeSlot($salon['tenant']);

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);

    $sent = SlotOffer::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('status', SlotOfferStatus::Sent->value)
        ->count();

    expect($sent)->toBeGreaterThan(0);

    $result = app(WaitlistSimulator::class)->expireOffer($salon['tenant']);

    expect($result['expired'])->toBe(1);
});

it('lists sandbox sms in the outbox and clears only those rows', function () {
    $salon = aBetaSalon();
    app(SampleData::class)->load($salon['tenant'], 'quiet');

    actingAsTenant($salon['staff'])
        ->get(route('beta-sandbox.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('outbox'));

    $bookings = Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count();
    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.outbox-clear'))
        ->assertRedirect();

    expect(Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('channel', MessageChannel::Sms->value)
        ->count())->toBe(0);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe($bookings);
    expect(Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe($customers);
});

it('sends due reminders immediately without skipping a day', function () {
    $salon = aBetaSalon();
    $hours = (int) config('booking.reminder_hours');
    $booking = aSandboxBooking($salon, CarbonImmutable::now('Europe/London')->addHours($hours - 1)->toDateTimeString());

    $sent = app(ReminderTrigger::class)->run($salon['tenant']);

    expect($sent)->toBe(1);
    expect(Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('booking_id', $booking->id)
        ->where('type', MessageType::Reminder->value)
        ->count())->toBeGreaterThan(0);
});

it('stores the flaky network toggle on the tenant sandbox state', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.flaky'), ['enabled' => true])
        ->assertRedirect();

    expect(SandboxState::flaky($salon['tenant']->fresh()))->toBeTrue();
    expect($salon['tenant']->fresh()->sandbox_state['last_action']['label'])->toBe('Turned on flaky network');

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.flaky'), ['enabled' => false])
        ->assertRedirect();

    expect(SandboxState::flaky($salon['tenant']->fresh()))->toBeFalse();
});

it('records last action after a skip so the status strip can show it', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.fast-forward'), ['interval' => 'week'])
        ->assertRedirect();

    actingAsTenant($salon['staff'])
        ->get(route('beta-sandbox.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.last_action.label', 'Skipped 1 week'));
});

it('loads a named sample size from the existing sample-data route', function () {
    $salon = aBetaSalon();

    actingAsTenant($salon['staff'])
        ->post(route('beta-sandbox.sample-data'), ['size' => 'quiet'])
        ->assertRedirect();

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(5);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(10);
});
