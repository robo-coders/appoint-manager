<?php

use App\Enums\BookingStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\Weekday;
use App\Mail\BookingConfirmedMail;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\RebookSend;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SmsAllowance;
use App\Services\Notifications\Notifier;
use App\Services\Rebooking\OverdueSubjects;
use App\Services\Rebooking\RebookMessenger;
use App\Services\Sms\RecordingSmsGateway;
use App\Services\Sms\SmsConsent;
use App\Services\Sms\SmsGateway;
use App\Support\SendWindow;
use App\Support\TenantContext;
use App\Support\TenantSlug;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| The rebooking chase, pointed at real phone numbers
|--------------------------------------------------------------------------
|
| Everything here is about what happens when the feature meets two hundred dog
| owners rather than a seeded database: sending once, not sending to somebody
| who said stop, not sending at ten at night, and knowing what it cost.
|
| The clock is 2026-09-01, a TUESDAY, deliberately. The existing
| `RebookingTest` freezes a Sunday, which is outside the default weekdays-only
| window — a fine default that would make every send test here silently pass by
| doing nothing.
|
*/

/**
 * @param  array<string, mixed>  $tenant
 * @param  array<string, mixed>  $service
 * @return array<string, mixed>
 */
function aChasingSalon(array $tenant = [], array $service = [], array $customer = []): array
{
    test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));

    $name = (string) ($tenant['name'] ?? 'Willow Street');

    $salon = Tenant::factory()->create(array_merge([
        'name' => $name,
        /*
         * Pinned to the name, because `TenantFactory` generates the slug from
         * `fake()->company()` and not from whatever name the caller passed. The
         * slug is in the booking URL, the URL is in the message, and the message
         * length is what half of this file asserts — so leaving the slug to
         * faker makes the segment counts randomly one or two.
         */
        'slug' => TenantSlug::generate($name),
        'timezone' => 'Europe/London',
        'email' => 'salon@example.com',
        'sms_cycle_used' => 0,
        'sms_prepaid' => 0,
        'settings' => ['rebooking' => ['messages_enabled' => true]],
    ], $tenant));

    app(TenantContext::class)->set($salon);

    $staff = User::factory()->create(['tenant_id' => $salon->id, 'is_bookable' => true, 'is_active' => true]);
    $svc = Service::factory()->create(array_merge([
        'tenant_id' => $salon->id,
        'suggested_interval_days' => 42,
        'price' => 3500,
    ], $service));
    $svc->staff()->attach($staff->id);

    AvailabilityRule::factory()->create([
        'tenant_id' => $salon->id,
        'user_id' => $staff->id,
        'weekday' => Weekday::Tuesday,
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
    ]);

    $client = Customer::factory()->create(array_merge([
        'tenant_id' => $salon->id,
        'email' => 'client@example.com',
        'phone' => '+447700900111',
    ], $customer));

    $subject = Subject::factory()->create([
        'tenant_id' => $salon->id,
        'customer_id' => $client->id,
        'name' => 'Bella',
    ]);

    return ['salon' => $salon, 'staff' => $staff, 'service' => $svc, 'customer' => $client, 'subject' => $subject];
}

/**
 * @param  array<string, mixed>  $salon
 */
function anOverdueVisit(array $salon, string $utcStart = '2026-07-20 09:00:00'): Booking
{
    // Two-tenant tests build one salon after the other, so the context left by
    // the last `aChasingSalon` is not necessarily this salon's.
    app(TenantContext::class)->set($salon['salon']);

    $start = CarbonImmutable::parse($utcStart, 'UTC');

    return Booking::factory()->create([
        'tenant_id' => $salon['salon']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $salon['customer']->id,
        'subject_id' => $salon['subject']->id,
        'starts_at' => $start,
        'ends_at' => $start->addHour(),
        'status' => BookingStatus::Confirmed,
        'price_at_booking' => $salon['service']->price->amount,
    ]);
}

function chaseMessages(Tenant $salon): int
{
    return Message::withoutGlobalScopes()
        ->where('tenant_id', $salon->id)
        ->where('type', MessageType::RebookDue->value)
        ->where('channel', MessageChannel::Sms->value)
        ->count();
}

beforeEach(function () {
    RecordingSmsGateway::$shouldFail = false;
    Mail::fake();
});

/*
|--------------------------------------------------------------------------
| One message per subject per due cycle
|--------------------------------------------------------------------------
*/

it('sends exactly one message when the job runs three days running', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    // The rule this test exists for. A subject overdue on Tuesday is overdue on
    // Wednesday and on Thursday, and before the claim table existed each of
    // those mornings was another text.
    foreach (['2026-09-01 10:00:00', '2026-09-02 10:00:00', '2026-09-03 10:00:00'] as $day) {
        test()->travelTo(CarbonImmutable::parse($day, 'UTC'));
        app(RebookMessenger::class)->sendDue($salon['salon']->fresh());
    }

    expect(chaseMessages($salon['salon']))->toBe(1)
        ->and(app(SmsGateway::class)->sent)->toHaveCount(1);
});

it('cannot be made to duplicate by running the job twice in the same minute', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    // A manual trigger on top of the scheduled one. Nothing has moved between
    // the two calls, so any guard that reads a timestamp would let this through.
    app(RebookMessenger::class)->sendDue($salon['salon']);
    app(RebookMessenger::class)->sendDue($salon['salon']->fresh());

    expect(chaseMessages($salon['salon']))->toBe(1);
});

it('refuses a second claim on the same cycle at the data layer', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    app(RebookMessenger::class)->sendDue($salon['salon']);

    // Straight at the table, bypassing every line of the job's logic. This is
    // what a second worker, a replayed job and a crash retry all reduce to.
    $duplicate = new RebookSend;
    $duplicate->forceFill([
        'tenant_id' => $salon['salon']->id,
        'subject_id' => $salon['subject']->id,
        'due_on' => '2026-08-31',
        'attempt' => 1,
        'segments' => 1,
        'sent_at' => now(),
    ]);

    expect(fn () => $duplicate->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('sends one follow-up after the configured gap and then stops for good', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    $gap = (int) config('rebooking.attempts.follow_up_gap_days');

    app(RebookMessenger::class)->sendDue($salon['salon']);
    expect(chaseMessages($salon['salon']))->toBe(1);

    // The day before the gap elapses: still silent.
    test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC')->addDays($gap - 1));
    app(RebookMessenger::class)->sendDue($salon['salon']->fresh());
    expect(chaseMessages($salon['salon']))->toBe(1);

    // The day it elapses: one follow-up.
    test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC')->addDays($gap));
    app(RebookMessenger::class)->sendDue($salon['salon']->fresh());
    expect(chaseMessages($salon['salon']))->toBe(2);

    // And then nothing, ever, for this cycle. Six weeks on and still overdue is
    // a phone call, not a third text.
    foreach ([$gap * 2, $gap * 3, $gap * 4] as $days) {
        test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC')->addDays($days));
        app(RebookMessenger::class)->sendDue($salon['salon']->fresh());
    }

    expect(chaseMessages($salon['salon']))->toBe(2);
});

it('leaves a chased subject on the overdue list for the salon to ring', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    $gap = (int) config('rebooking.attempts.follow_up_gap_days');

    // Both attempts spent: the first chase and the follow-up.
    app(RebookMessenger::class)->sendDue($salon['salon']);
    test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC')->addDays($gap));
    app(RebookMessenger::class)->sendDue($salon['salon']->fresh());

    // Six weeks on, still overdue, still not booked. Silence is better than
    // nagging — but she must still be able to see them and ring them.
    test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC')->addDays(90));

    $rows = app(OverdueSubjects::class)->forTenant($salon['salon']->fresh());
    $run = app(RebookMessenger::class)->dryRun($salon['salon']->fresh());

    expect($rows)->toHaveCount(1)
        ->and($run['count'])->toBe(0)
        ->and($run['suppressed'][0]['reason'])->toBe('attempts_used')
        ->and(chaseMessages($salon['salon']))->toBe(2);
});

it('starts a new cycle when the subject books, and chases again when that lapses', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    app(RebookMessenger::class)->sendDue($salon['salon']);
    expect(chaseMessages($salon['salon']))->toBe(1);

    // They book. The last visit moves, so the due date moves, so the cycle key
    // moves — and that is the only thing that starts a new cycle.
    anOverdueVisit($salon, '2026-09-02 09:00:00');

    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC'));
    $salon['subject']->forceFill(['rebook_contacted_at' => null])->save();
    app(RebookMessenger::class)->sendDue($salon['salon']->fresh());

    expect(chaseMessages($salon['salon']))->toBe(2)
        ->and(RebookSend::withoutGlobalScopes()->where('tenant_id', $salon['salon']->id)->distinct()->count('due_on'))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| STOP, and consent
|--------------------------------------------------------------------------
*/

it('appends the opt-out notice to every chase and counts it in the budget', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    $run = app(RebookMessenger::class)->dryRun($salon['salon']);
    $body = $run['messages'][0]['body'];

    expect($body)->toContain(trim((string) config('rebooking.message.opt_out_suffix')))
        // Counted, not bolted on afterwards: the reported length includes it.
        ->and($run['messages'][0]['characters'])->toBe(mb_strlen($body));
});

it('suppresses the next chase after STOP and restores it after START', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    app(SmsConsent::class)->optOut($salon['customer'], 'test');

    app(RebookMessenger::class)->sendDue($salon['salon']);
    expect(chaseMessages($salon['salon']))->toBe(0);

    app(SmsConsent::class)->optIn($salon['customer']->fresh());
    app(RebookMessenger::class)->sendDue($salon['salon']->fresh());

    expect(chaseMessages($salon['salon']))->toBe(1);
});

it('acts on an inbound STOP whatever the case and punctuation', function () {
    foreach (['STOP', 'stop', '  Stop.  ', 'STOPALL', 'unsubscribe', 'Cancel!', 'END', 'quit'] as $word) {
        $salon = aChasingSalon();
        anOverdueVisit($salon);
        app(RebookMessenger::class)->sendDue($salon['salon']);

        $this->post(route('twilio.inbound'), [
            'From' => $salon['customer']->phone,
            'Body' => $word,
        ])->assertOk();

        expect($salon['customer']->fresh()->sms_opted_out_at)->not->toBeNull("'{$word}' should opt out");
    }
});

it('reverses an opt-out on START and on UNSTOP', function () {
    foreach (['START', 'unstop'] as $word) {
        $salon = aChasingSalon();
        anOverdueVisit($salon);
        app(RebookMessenger::class)->sendDue($salon['salon']);
        app(SmsConsent::class)->optOut($salon['customer'], 'test');

        $this->post(route('twilio.inbound'), [
            'From' => $salon['customer']->phone,
            'Body' => $word,
        ])->assertOk();

        expect($salon['customer']->fresh()->sms_opted_out_at)->toBeNull("'{$word}' should opt back in");
    }
});

it('does not opt somebody out of a salon they did not text', function () {
    // The webhook payload cannot say which tenant a reply belongs to — inbound
    // arrives on one platform number. The most recent message to that number
    // can, and it is the only thing that can.
    $ours = aChasingSalon(['name' => 'Willow Street']);
    anOverdueVisit($ours);

    $theirs = aChasingSalon(['name' => 'Elm Road'], customer: ['phone' => $ours['customer']->phone, 'email' => 'same@example.com']);
    anOverdueVisit($theirs);

    app(RebookMessenger::class)->sendDue($ours['salon']);

    $this->post(route('twilio.inbound'), [
        'From' => $ours['customer']->phone,
        'Body' => 'STOP',
    ])->assertOk();

    expect($ours['customer']->fresh()->sms_opted_out_at)->not->toBeNull()
        ->and($theirs['customer']->fresh()->sms_opted_out_at)->toBeNull();
});

it('keeps an opt-out inside one tenant when the same person uses two salons', function () {
    $ours = aChasingSalon();
    $theirs = aChasingSalon(customer: ['phone' => $ours['customer']->phone, 'email' => 'same@example.com']);
    anOverdueVisit($ours);
    anOverdueVisit($theirs);

    app(SmsConsent::class)->optOut($ours['customer'], 'test');

    app(RebookMessenger::class)->sendDue($ours['salon']);
    app(RebookMessenger::class)->sendDue($theirs['salon']);

    expect(chaseMessages($ours['salon']))->toBe(0)
        ->and(chaseMessages($theirs['salon']))->toBe(1);
});

it('still sends a booking confirmation to somebody who opted out of marketing', function () {
    $salon = aChasingSalon();
    app(SmsConsent::class)->optOut($salon['customer'], 'test');

    $booking = anOverdueVisit($salon, '2026-09-08 09:00:00');
    app(Notifier::class)->bookingConfirmed($booking);

    // The service message they asked for by booking. Withholding it would put
    // somebody outside a locked salon door because they once replied STOP.
    expect(Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['salon']->id)
        ->where('type', MessageType::BookingConfirmed->value)
        ->where('channel', MessageChannel::Sms->value)
        ->count())->toBe(1);

    Mail::assertQueued(BookingConfirmedMail::class);
});

it('keeps an opted-out subject on the overdue list with a marker', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    app(SmsConsent::class)->optOut($salon['customer'], 'test');

    $rows = app(OverdueSubjects::class)->forTenant($salon['salon']);
    $run = app(RebookMessenger::class)->dryRun($salon['salon']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['opted_out'])->toBeTrue()
        ->and($run['count'])->toBe(0)
        ->and($run['suppressed'][0]['reason'])->toBe('opted_out');
});

it('answers 200 to an inbound message from a number it has never texted', function () {
    $this->post(route('twilio.inbound'), ['From' => '+447700900999', 'Body' => 'STOP'])->assertOk();
    $this->post(route('twilio.inbound'), ['From' => '', 'Body' => 'STOP'])->assertOk();
    $this->post(route('twilio.inbound'), ['From' => '+447700900999', 'Body' => 'what time do you open'])->assertOk();
});

/*
|--------------------------------------------------------------------------
| When messages go out
|--------------------------------------------------------------------------
*/

it('does not send outside the window, in the tenant\'s own timezone', function () {
    // Sydney is UTC+10 in September. 10:00 UTC is 20:00 there — inside our
    // 09:00–18:00 window if you read the server's clock, and firmly outside it
    // if you read the salon's, which is the one the client's phone is next to.
    $salon = aChasingSalon(['timezone' => 'Australia/Sydney']);
    anOverdueVisit($salon);

    expect(SendWindow::isOpen($salon['salon']))->toBeFalse()
        ->and(app(RebookMessenger::class)->sendDue($salon['salon']))->toBe(0)
        ->and(chaseMessages($salon['salon']))->toBe(0);

    // A London salon at the same instant is at 11:00 and is sent for.
    $london = aChasingSalon(['timezone' => 'Europe/London']);
    anOverdueVisit($london);

    expect(SendWindow::isOpen($london['salon']))->toBeTrue()
        ->and(app(RebookMessenger::class)->sendDue($london['salon']))->toBe(1);
});

it('waits for the next window rather than dropping the subject', function () {
    $salon = aChasingSalon(['timezone' => 'Australia/Sydney']);
    anOverdueVisit($salon);

    // 20:00 Sydney: nothing.
    expect(app(RebookMessenger::class)->sendDue($salon['salon']))->toBe(0);

    // 09:30 Sydney the next morning, which is 23:30 UTC the same day.
    test()->travelTo(CarbonImmutable::parse('2026-09-01 23:30:00', 'UTC'));

    expect(app(RebookMessenger::class)->sendDue($salon['salon']->fresh()))->toBe(1)
        ->and(chaseMessages($salon['salon']))->toBe(1);
});

it('does not send at the weekend by default and does when a tenant asks for it', function () {
    // Saturday.
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    test()->travelTo(CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC'));

    expect(app(RebookMessenger::class)->sendDue($salon['salon']))->toBe(0);

    $settings = $salon['salon']->settings;
    $settings['rebooking']['send_window'] = ['start' => '09:00', 'end' => '18:00', 'days' => [1, 2, 3, 4, 5, 6, 7]];
    $salon['salon']->forceFill(['settings' => $settings])->save();

    expect(app(RebookMessenger::class)->sendDue($salon['salon']->fresh()))->toBe(1);
});

it('describes the window in the operator\'s own words', function () {
    $salon = aChasingSalon();

    expect(SendWindow::describe($salon['salon']))->toBe('09:00 to 18:00, weekdays');
});

/*
|--------------------------------------------------------------------------
| Message length and cost
|--------------------------------------------------------------------------
*/

it('reports a two-segment message in the dry run when the salon name is long', function () {
    $salon = aChasingSalon(['name' => 'Battersea and Clapham Junction Dog Grooming and Pet Care Company']);
    anOverdueVisit($salon);

    $run = app(RebookMessenger::class)->dryRun($salon['salon']);

    expect($run['messages'][0]['characters'])->toBeGreaterThan(160)
        ->and($run['messages'][0]['segments'])->toBe(2)
        ->and($run['messages'][0]['encoding'])->toBe('GSM-7')
        ->and($run['over_one_segment'])->toBe(1)
        ->and($run['segments'])->toBe(2);
});

it('reports the UCS-2 penalty for an accented name without mangling it', function () {
    $salon = aChasingSalon();
    $salon['subject']->forceFill(['name' => 'Zoë'])->save();
    anOverdueVisit($salon);

    $run = app(RebookMessenger::class)->dryRun($salon['salon']->fresh());

    expect($run['messages'][0]['body'])->toContain('Zoë')
        ->and($run['messages'][0]['encoding'])->toBe('UCS-2')
        // Under 160 characters and still two segments, because UCS-2 caps at 70.
        ->and($run['messages'][0]['characters'])->toBeLessThan(160)
        ->and($run['messages'][0]['segments'])->toBeGreaterThan(1);
});

it('decrements the allowance by segments, not by messages', function () {
    $salon = aChasingSalon(['name' => 'Battersea and Clapham Junction Dog Grooming and Pet Care Company']);
    anOverdueVisit($salon);

    app(RebookMessenger::class)->sendDue($salon['salon']);

    $message = Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['salon']->id)
        ->where('channel', MessageChannel::Sms->value)
        ->first();

    // One message, two segments, two off the allowance — because that is what
    // the carrier bills. Counting messages would let a 200 pack cost us 400.
    expect($message->segments)->toBe(2)
        ->and($salon['salon']->fresh()->sms_cycle_used)->toBe(2);
});

it('will not send a two-segment message on a one-segment remainder', function () {
    $salon = aChasingSalon([
        'name' => 'Battersea and Clapham Junction Dog Grooming and Pet Care Company',
        'sms_included_override' => 200,
        'sms_cycle_used' => 199,
    ]);
    anOverdueVisit($salon);

    expect(app(SmsAllowance::class)->canSend($salon['salon'], 1))->toBeTrue()
        ->and(app(SmsAllowance::class)->canSend($salon['salon'], 2))->toBeFalse();

    app(RebookMessenger::class)->sendDue($salon['salon']->fresh());

    expect(chaseMessages($salon['salon']))->toBe(0)
        ->and($salon['salon']->fresh()->sms_cycle_used)->toBe(199);
});

it('does not cut the booking link off the end of a transactional message', function () {
    $salon = aChasingSalon(['name' => str_repeat('Willow Street Grooming ', 8)]);
    $booking = anOverdueVisit($salon, '2026-09-08 09:00:00');

    app(Notifier::class)->bookingConfirmed($booking);

    $body = (string) Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['salon']->id)
        ->where('channel', MessageChannel::Sms->value)
        ->value('body');

    // `Str::limit($body, 160)` truncated by characters from the end, and the end
    // is where the link lives. A confirmation with half a URL in it is useless.
    expect($body)->toContain('/b/'.$booking->public_token);
});

/*
|--------------------------------------------------------------------------
| Failure, retry, and the truth about what was sent
|--------------------------------------------------------------------------
*/

it('retries tomorrow when the provider rejects the send today', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    RecordingSmsGateway::$shouldFail = true;

    try {
        app(RebookMessenger::class)->sendDue($salon['salon']);
    } catch (Throwable) {
        // The job is allowed to throw. What matters is the state it leaves.
    }

    expect($salon['salon']->fresh()->sms_cycle_used)->toBe(0)
        ->and(RebookSend::withoutGlobalScopes()->where('tenant_id', $salon['salon']->id)->count())->toBe(0)
        ->and($salon['subject']->fresh()->rebook_contacted_at)->toBeNull()
        ->and($salon['subject']->fresh()->rebook_failed_sends)->toBe(1);

    RecordingSmsGateway::$shouldFail = false;
    test()->travelTo(CarbonImmutable::parse('2026-09-02 10:00:00', 'UTC'));

    expect(app(RebookMessenger::class)->sendDue($salon['salon']->fresh()))->toBe(1);
});

it('stops attempting a number that keeps being rejected, and flags it', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    RecordingSmsGateway::$shouldFail = true;
    $limit = (int) config('rebooking.attempts.max_send_failures');

    for ($day = 1; $day <= $limit; $day++) {
        test()->travelTo(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC')->addDays($day - 1));

        try {
            app(RebookMessenger::class)->sendDue($salon['salon']->fresh());
        } catch (Throwable) {
        }
    }

    expect($salon['subject']->fresh()->rebook_send_blocked_at)->not->toBeNull()
        ->and($salon['subject']->fresh()->rebook_failed_sends)->toBe($limit);

    // And it stays flagged rather than being tried a fourth time.
    RecordingSmsGateway::$shouldFail = false;
    test()->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'));

    expect(app(RebookMessenger::class)->sendDue($salon['salon']->fresh()))->toBe(0);

    $rows = app(OverdueSubjects::class)->forTenant($salon['salon']->fresh());
    expect($rows[0]['number_failing'])->toBeTrue();
});

it('starts chasing again when the salon corrects the number', function () {
    $salon = aChasingSalon();
    $salon['subject']->forceFill(['rebook_send_blocked_at' => now(), 'rebook_failed_sends' => 3])->save();

    $salon['customer']->update(['phone' => '+447700900222']);

    expect($salon['subject']->fresh()->rebook_send_blocked_at)->toBeNull()
        ->and($salon['subject']->fresh()->rebook_failed_sends)->toBe(0);
});

it('records a delivery failure against the message so the salon can see it', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    app(RebookMessenger::class)->sendDue($salon['salon']);

    $message = Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['salon']->id)
        ->where('channel', MessageChannel::Sms->value)
        ->first();

    $this->post(route('twilio.status'), [
        'MessageSid' => $message->provider_id,
        'MessageStatus' => 'undelivered',
        'ErrorCode' => '30003',
        'ErrorMessage' => 'Unreachable destination handset',
    ])->assertOk();

    expect($message->fresh()->status)->toBe(MessageStatus::Undelivered)
        ->and($message->fresh()->provider_error)->toContain('Unreachable destination handset')
        // Billed on accept, and not refunded. Visible, though, which was the gap.
        ->and($salon['salon']->fresh()->sms_cycle_used)->toBe(1)
        ->and($salon['subject']->fresh()->rebook_failed_sends)->toBe(1);
});

it('shows the send log on the overdue page including what failed', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);
    app(RebookMessenger::class)->sendDue($salon['salon']);

    $message = Message::withoutGlobalScopes()
        ->where('tenant_id', $salon['salon']->id)
        ->where('channel', MessageChannel::Sms->value)
        ->first();

    $this->post(route('twilio.status'), [
        'MessageSid' => $message->provider_id,
        'MessageStatus' => 'failed',
        'ErrorCode' => '21614',
        'ErrorMessage' => 'Not a mobile number',
    ])->assertOk();

    $owner = User::factory()->create(['tenant_id' => $salon['salon']->id, 'role' => 'owner']);

    actingAsTenant($owner)
        ->get(route('overdue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Overdue/Index')
            ->where('recent_sends.0.failed', true)
            ->where('recent_sends.0.status', 'failed')
            ->where('window', '09:00 to 18:00, weekdays')
            ->has('recent_sends.0.error'));
});

/*
|--------------------------------------------------------------------------
| The ceiling, and the trial
|--------------------------------------------------------------------------
*/

it('takes the hard ceiling from config, not from a number in the allowance class', function () {
    $salon = aChasingSalon();

    expect(app(SmsAllowance::class)->ceiling($salon['salon']))
        ->toBe((int) config('billing.sms_hard_ceiling'));

    config(['billing.sms_hard_ceiling' => 750]);

    expect(app(SmsAllowance::class)->ceiling($salon['salon']->fresh()))->toBe(750);
});

it('reads the trial allowance from config rather than from the reset rule', function () {
    $salon = aChasingSalon(['trial_ends_at' => now()->addDays(20), 'subscription_status' => 'trial']);

    expect($salon['salon']->onTrial())->toBeTrue()
        ->and(app(SmsAllowance::class)->included($salon['salon']))->toBe((int) config('billing.sms_included'));

    config()->set('billing.sms_trial_included', 50);

    expect(app(SmsAllowance::class)->included($salon['salon']))->toBe(50);

    // And a paying tenant is unaffected by the trial key.
    $paying = aChasingSalon(['subscription_status' => 'active', 'trial_ends_at' => now()->subDay()]);
    expect(app(SmsAllowance::class)->included($paying['salon']))->toBe((int) config('billing.sms_included'));
});

it('can be made not to reset a trial cycle monthly', function () {
    config()->set('billing.sms_trial_resets_monthly', false);

    $salon = aChasingSalon([
        'trial_ends_at' => now()->addDays(20),
        'subscription_status' => 'trial',
        'sms_cycle_used' => 120,
        'sms_cycle_started_at' => now()->subMonths(2),
    ]);

    app(SmsAllowance::class)->maybeResetCycle($salon['salon']);

    expect($salon['salon']->fresh()->sms_cycle_used)->toBe(120);
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('sends to exactly one subject when told to', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    $other = Customer::factory()->create(['tenant_id' => $salon['salon']->id, 'phone' => '+447700900333']);
    $otherSubject = Subject::factory()->create([
        'tenant_id' => $salon['salon']->id,
        'customer_id' => $other->id,
        'name' => 'Max',
    ]);
    Booking::factory()->create([
        'tenant_id' => $salon['salon']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $other->id,
        'subject_id' => $otherSubject->id,
        'starts_at' => CarbonImmutable::parse('2026-07-20 11:00:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-07-20 12:00:00', 'UTC'),
        'status' => BookingStatus::Confirmed,
        'price_at_booking' => 3500,
    ]);

    expect(app(OverdueSubjects::class)->forTenant($salon['salon']))->toHaveCount(2);

    $this->artisan('rebooking:send', [
        '--tenant' => $salon['salon']->slug,
        '--subject' => [(string) $salon['subject']->id],
    ])->assertSuccessful();

    expect(chaseMessages($salon['salon']))->toBe(1)
        ->and(Message::withoutGlobalScopes()->where('tenant_id', $salon['salon']->id)->where('to', '+447700900111')->exists())->toBeTrue()
        ->and(Message::withoutGlobalScopes()->where('tenant_id', $salon['salon']->id)->where('to', '+447700900333')->exists())->toBeFalse();
});

it('refuses a subject id without a tenant', function () {
    $this->artisan('rebooking:send', ['--subject' => ['1']])->assertFailed();
});

it('sends nothing on a dry run', function () {
    $salon = aChasingSalon();
    anOverdueVisit($salon);

    $this->artisan('rebooking:send', ['--tenant' => $salon['salon']->slug, '--dry-run' => true])
        ->assertSuccessful();

    expect(chaseMessages($salon['salon']))->toBe(0);
});

it('can be told to ignore the window for a deliberate test send', function () {
    $salon = aChasingSalon(['timezone' => 'Australia/Sydney']);
    anOverdueVisit($salon);

    $this->artisan('rebooking:send', ['--tenant' => $salon['salon']->slug])->assertSuccessful();
    expect(chaseMessages($salon['salon']))->toBe(0);

    $this->artisan('rebooking:send', ['--tenant' => $salon['salon']->slug, '--ignore-window' => true])
        ->assertSuccessful();

    expect(chaseMessages($salon['salon']))->toBe(1);
});
