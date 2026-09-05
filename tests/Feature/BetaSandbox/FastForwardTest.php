<?php

use App\BetaSandbox\FastForward;
use App\BetaSandbox\SampleData;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageType;
use App\Enums\SlotOfferStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Sms\RecordingSmsGateway;
use App\Services\Sms\SmsGateway;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * "Skip 1 day" and "Skip 1 week". See BETA_SANDBOX.md.
 *
 * The claim under test is the one the brief insists on: this is not cosmetic.
 * Timestamps move *and* the automation that was waiting on them runs — the
 * reminder is sent, the unpaid hold is let go, the expired request is declined,
 * the unclaimed offer is retired. Each of those is asserted by its consequence
 * (a `messages` row, a status change) rather than by a return value, because a
 * counter is easy to make right while the thing it counts never happened.
 *
 * The second claim is that it is a strictly local event: a second salon sits
 * beside the first throughout, with the same data, and nothing about it moves.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London'));
    Mail::fake();
});

it('moves this salon\'s appointments backwards by a day', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-15 10:00:00');
    $was = $booking->starts_at;

    app(FastForward::class)->run($salon['tenant'], 'day');

    expect($booking->fresh()->starts_at->toDateTimeString())
        ->toBe($was->copy()->subDay()->toDateTimeString());
    expect($booking->fresh()->ends_at->toDateTimeString())
        ->toBe($booking->ends_at->copy()->subDay()->toDateTimeString());
});

it('moves a week when a week is asked for', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-10-01 10:00:00');
    $was = $booking->starts_at;

    app(FastForward::class)->run($salon['tenant'], 'week');

    expect($booking->fresh()->starts_at->toDateTimeString())
        ->toBe($was->copy()->subWeek()->toDateTimeString());
});

it('leaves a null timestamp null rather than inventing a date for it', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-15 10:00:00');

    expect($booking->deposit_paid_at)->toBeNull();

    app(FastForward::class)->run($salon['tenant'], 'day');

    expect($booking->fresh()->deposit_paid_at)->toBeNull();
    expect($booking->fresh()->cancelled_at)->toBeNull();
});

it('never moves another salon\'s data', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    $mineBooking = aSandboxBooking($mine, '2026-09-15 10:00:00');
    $theirsBooking = aSandboxBooking($theirs, '2026-09-15 10:00:00');
    $theirsWas = $theirsBooking->starts_at->toDateTimeString();

    app(FastForward::class)->run($mine['tenant'], 'week');

    expect($mineBooking->fresh()->starts_at->toDateTimeString())->not->toBe($theirsWas);
    expect($theirsBooking->fresh()->starts_at->toDateTimeString())->toBe($theirsWas);
    expect($theirsBooking->fresh()->created_at->toDateTimeString())
        ->toBe($theirsBooking->created_at->toDateTimeString());
});

it('does not move the salon\'s own billing dates', function () {
    $salon = aBetaSalon();
    $trial = $salon['tenant']->trial_ends_at->toDateTimeString();

    app(FastForward::class)->run($salon['tenant'], 'week');

    // Deliberate: burning trial days would eventually put the shop into
    // read-only and lock the tester out of the sandbox's own buttons.
    expect(Tenant::query()->find($salon['tenant']->id)->trial_ends_at->toDateTimeString())->toBe($trial);
});

/*
|--------------------------------------------------------------------------
| The automation actually running
|--------------------------------------------------------------------------
*/

it('sends the reminder that came due while time was passing', function () {
    $salon = aBetaSalon();

    // Reminders go out `booking.reminder_hours` before the appointment. This
    // one sits just outside that window — twelve hours beyond it — so nothing
    // is due now, and one press of "Skip 1 day" brings it inside.
    $hours = (int) config('booking.reminder_hours');
    $booking = aSandboxBooking($salon, CarbonImmutable::now('Europe/London')->addHours($hours + 12)->toDateTimeString());

    expect(sandboxReminders($salon['tenant'], $booking))->toBe(0);

    $result = app(FastForward::class)->run($salon['tenant'], 'day');

    expect($result['reminders'])->toBe(1);
    expect(sandboxReminders($salon['tenant'], $booking))->toBeGreaterThan(0);
});

it('does not remind the same customer twice on a second press', function () {
    $salon = aBetaSalon();
    $hours = (int) config('booking.reminder_hours');
    $booking = aSandboxBooking($salon, CarbonImmutable::now('Europe/London')->addHours($hours + 12)->toDateTimeString());

    app(FastForward::class)->run($salon['tenant'], 'day');
    $after = sandboxReminders($salon['tenant'], $booking);

    $second = app(FastForward::class)->run($salon['tenant'], 'day');

    expect($second['reminders'])->toBe(0);
    expect(sandboxReminders($salon['tenant'], $booking))->toBe($after);
});

it('releases an unpaid checkout hold once it has aged past its window', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-20 10:00:00');

    $booking->forceFill([
        'status' => BookingStatus::Pending,
        'deposit_status' => DepositStatus::Required,
        'request_expires_at' => null,
    ])->save();

    // A hold created moments ago is not expired, whatever command is run.
    $result = app(FastForward::class)->run($salon['tenant'], 'day');

    expect($result['released'])->toBe(1);
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('declines a booking request whose approval window ran out', function () {
    $salon = aBetaSalon();
    $booking = aSandboxBooking($salon, '2026-09-20 10:00:00');

    $booking->forceFill([
        'status' => BookingStatus::Pending,
        'deposit_status' => DepositStatus::None,
        'request_expires_at' => CarbonImmutable::now()->addHours(6),
    ])->save();

    $result = app(FastForward::class)->run($salon['tenant'], 'day');

    expect($result['declined'])->toBe(1);
    expect($booking->fresh()->status)->toBe(BookingStatus::Declined);
});

it('retires a waitlist offer nobody claimed', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    $offer = aSandboxOffer($mine, CarbonImmutable::now()->addHours(6));
    $untouched = aSandboxOffer($theirs, CarbonImmutable::now()->addHours(6));

    $result = app(FastForward::class)->run($mine['tenant'], 'day');

    expect($result['offers'])->toBe(1);
    expect($offer->fresh()->status)->toBe(SlotOfferStatus::Expired);

    // The other salon's offer is equally overdue in wall-clock terms and must
    // still be untouched: this sweep is scoped, not global.
    expect($untouched->fresh()->status)->toBe(SlotOfferStatus::Sent);
});

it('sends nothing to a real phone or inbox while all of that happens', function () {
    $salon = aBetaSalon();
    $sms = app(SmsGateway::class);

    app(SampleData::class)->load($salon['tenant']);
    app(FastForward::class)->run($salon['tenant'], 'week');

    expect($sms)->toBeInstanceOf(RecordingSmsGateway::class);
    expect($sms->sent)->toBe([]);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('still records what it would have sent, so the send log is honest', function () {
    $salon = aBetaSalon();
    $hours = (int) config('booking.reminder_hours');
    $booking = aSandboxBooking($salon, CarbonImmutable::now('Europe/London')->addHours($hours + 12)->toDateTimeString());

    app(FastForward::class)->run($salon['tenant'], 'day');

    $message = Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->where('booking_id', $booking->id)
        ->where('type', MessageType::Reminder->value)
        ->first();

    expect($message)->not->toBeNull();
    // Marked sent rather than left queued: no worker will ever pick it up, and
    // a permanently queued row reads as a broken product.
    expect($message->status->value)->toBe('sent');
});

it('goes through the whole thing on a real sample shop without falling over', function () {
    $salon = aBetaSalon();

    app(SampleData::class)->load($salon['tenant']);

    $before = Booking::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->min('starts_at');

    app(FastForward::class)->run($salon['tenant'], 'week');

    $after = Booking::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->min('starts_at');

    expect(CarbonImmutable::parse($after)->toDateTimeString())
        ->toBe(CarbonImmutable::parse($before)->subWeek()->toDateTimeString());

    actingAsTenant($salon['staff'])->get(route('diary.index'))->assertOk();
    actingAsTenant($salon['staff'])->get(route('dashboard'))->assertOk();
});

/** Reminders logged for one booking. */
function sandboxReminders(Tenant $tenant, Booking $booking): int
{
    return Message::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('booking_id', $booking->id)
        ->where('type', MessageType::Reminder->value)
        ->count();
}

/**
 * A live waitlist offer in a salon, expiring at the given moment.
 *
 * @param  array{tenant: Tenant, staff: User, service: Service}  $salon
 */
function aSandboxOffer(array $salon, CarbonImmutable $expiresAt): SlotOffer
{
    $context = app(TenantContext::class);
    $context->set($salon['tenant']);

    try {
        $customer = Customer::query()->create([
            'name' => 'Waiting Person',
            'phone' => '07700900500',
        ]);

        $entry = WaitlistEntry::query()->create([
            'customer_id' => $customer->id,
            'service_id' => $salon['service']->id,
            'is_active' => true,
        ]);

        return SlotOffer::query()->create([
            'waitlist_entry_id' => $entry->id,
            'service_id' => $salon['service']->id,
            'staff_id' => $salon['staff']->id,
            'starts_at' => CarbonImmutable::now()->addWeek()->utc(),
            'ends_at' => CarbonImmutable::now()->addWeek()->addHour()->utc(),
            'status' => SlotOfferStatus::Sent,
            'expires_at' => $expiresAt->utc(),
        ]);
    } finally {
        $context->clear();
    }
}
