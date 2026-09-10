<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Stripe\FakeStripeGateway;
use App\Services\Stripe\StripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The customer-facing manage page. `.design/mockups/booking/manage-booking.dc.html`.
 *
 * `SelfServiceTest` covers the happy paths — an atomic reschedule, a refund on
 * each side of the window, a tampered token. This file covers the edges the
 * mockup's three states imply and that file does not: what a dead token is
 * allowed to reveal, the exact second the refund window turns over, a refund
 * Stripe refuses, and whether a booking that is over can still be acted on by
 * somebody posting straight at the endpoint.
 */
function manageTenant(string $suffix = 'a'): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/London',
        'city' => 'East Kilbride',
        'postcode' => 'G74 1AB',
        'phone' => '01355 000111',
        'stripe_account_id' => 'acct_'.$suffix,
        'stripe_onboarding_complete' => true,
    ]);
    app(StripeGateway::class)->completeAccount('acct_'.$suffix);

    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'duration_minutes' => 60,
        'buffer_minutes' => 0,
        'is_active' => true,
        'deposit_amount' => 1000,
    ]);
    $service->staff()->attach($staff->id);

    foreach (Weekday::cases() as $weekday) {
        AvailabilityRule::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $staff->id,
            'weekday' => $weekday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    return compact('tenant', 'staff', 'service');
}

function manageBooking(array $salon, array $overrides = []): Booking
{
    $starts = CarbonImmutable::parse($overrides['starts_at'] ?? '2026-03-10 09:00:00', 'Europe/London')->utc();

    return Booking::factory()->create(array_merge([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => Customer::factory()->create(['tenant_id' => $salon['tenant']->id])->id,
        'starts_at' => $starts,
        'ends_at' => $starts->addHour(),
        'status' => BookingStatus::Confirmed,
        'deposit_status' => DepositStatus::Paid,
        'deposit_at_booking' => 1000,
        'stripe_payment_intent_id' => 'pi_'.uniqid(),
        'source' => BookingSource::Online,
    ], $overrides, ['starts_at' => $starts, 'ends_at' => $starts->addHour()]));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
    RateLimiter::clear('booking-manage');
});

describe('what a dead link may reveal', function () {
    /*
     * The whole point: a token that never existed and a token that existed and
     * expired must be indistinguishable, or the endpoint is an oracle for
     * guessing valid tokens.
     */
    it('answers every unresolvable token with one identical response', function () {
        $neverExisted = $this->get(route('booking.manage.show', Str::uuid()));
        $wrongShape = $this->get(route('booking.manage.show', 'abc'));
        $alsoNever = $this->get(route('booking.manage.show', Str::uuid()));

        expect($neverExisted->status())->toBe($wrongShape->status())
            ->and($neverExisted->getContent())->toBe($wrongShape->getContent())
            ->and($neverExisted->getContent())->toBe($alsoNever->getContent());
    });

    it('says the link is not active rather than showing a framework 404', function () {
        $this->get(route('booking.manage.show', Str::uuid()))
            ->assertNotFound()
            ->assertSee('This booking link is no longer active')
            ->assertDontSee('Not Found', false);
    });

    it('rejects a token too short to be one without touching the database', function () {
        DB::enableQueryLog();
        $this->get(route('booking.manage.show', 'abc'))->assertNotFound();

        expect(collect(DB::getQueryLog())->pluck('query')->filter(
            fn (string $sql) => str_contains($sql, 'public_token'),
        ))->toBeEmpty();
    });

    it('gives the write endpoints the same generic answer, and changes nothing', function () {
        $this->postJson(route('booking.manage.cancel', Str::uuid()))
            ->assertNotFound()
            ->assertJson(['message' => 'This booking link is no longer active.']);

        $this->postJson(route('booking.manage.reschedule', Str::uuid()), [
            'starts_at' => CarbonImmutable::parse('2026-03-10 11:00', 'Europe/London')->utc()->toIso8601String(),
        ])->assertNotFound();

        $this->getJson(route('booking.manage.availability', Str::uuid()).'?from=2026-03-09&to=2026-03-15')->assertNotFound();
    });

    it('resolves a token only within its own tenant', function () {
        $one = manageTenant('one');
        $two = manageTenant('two');

        $theirs = manageBooking($two);

        $this->get(route('booking.manage.show', $theirs->public_token))
            ->assertOk()
            ->assertSee($two['tenant']->name, false)
            ->assertDontSee($one['tenant']->name, false);
    });
});

describe('the refund window boundary', function () {
    /*
     * `refund_window_hours` is 48 and the appointment is 09:00 on the 10th, so
     * the window turns over at 09:00 on the 8th. One second either side of that
     * instant is a different answer about somebody's money, which is why this
     * is asserted at the second rather than at the day.
     */
    $cutoff = '2026-03-08 09:00:00';

    it('refunds one second before the cutoff', function () use ($cutoff) {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        $this->travelTo(CarbonImmutable::parse($cutoff, 'Europe/London')->subSecond());
        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertOk();

        expect($booking->fresh()->deposit_status)->toBe(DepositStatus::Refunded);
    });

    it('keeps the deposit exactly at the cutoff', function () use ($cutoff) {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        $this->travelTo(CarbonImmutable::parse($cutoff, 'Europe/London'));
        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertOk();

        expect($booking->fresh()->deposit_status)->toBe(DepositStatus::Paid);
    });

    it('keeps the deposit one second after the cutoff', function () use ($cutoff) {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        $this->travelTo(CarbonImmutable::parse($cutoff, 'Europe/London')->addSecond());
        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertOk();

        expect($booking->fresh()->deposit_status)->toBe(DepositStatus::Paid);
    });
});

describe('a refund Stripe refuses', function () {
    it('leaves the booking owing a refund rather than marked refunded', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        $gateway = app(StripeGateway::class);
        expect($gateway)->toBeInstanceOf(FakeStripeGateway::class);
        $gateway->throwOnRefund = true;

        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertOk();

        $fresh = $booking->fresh();

        expect($fresh->status)->toBe(BookingStatus::Cancelled)
            ->and($fresh->deposit_status)->toBe(DepositStatus::RefundPending)
            ->and($fresh->deposit_status)->not->toBe(DepositStatus::Refunded);
    });

    /*
     * The owner has to be able to see it. A customer ringing about a refund
     * that never arrived, against a record that says the booking is simply
     * cancelled, is the failure this guards.
     */
    it('shows as refund pending on the operator record', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        $gateway = app(StripeGateway::class);
        $gateway->throwOnRefund = true;
        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertOk();

        $this->actingAs($salon['staff'])
            ->get(route('bookings.show', $booking->id))
            ->assertOk()
            ->assertSee(DepositStatus::RefundPending->label(), false);
    });
});

describe('a booking that is over or already cancelled', function () {
    it('states the status instead of offering the live controls', function () {
        $salon = manageTenant();
        $done = manageBooking($salon, ['starts_at' => '2026-02-01 09:00:00', 'status' => BookingStatus::Completed]);

        $response = $this->get(route('booking.manage.show', $done->public_token))->assertOk();

        expect($response->getContent())->toContain('"state":"finished"')
            ->and($response->getContent())->toContain('"can_cancel":false')
            ->and($response->getContent())->toContain('"can_reschedule":false');
    });

    it('shows a cancelled booking as cancelled, with no controls', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon, ['status' => BookingStatus::Cancelled]);

        $response = $this->get(route('booking.manage.show', $booking->public_token))->assertOk();

        expect($response->getContent())->toContain('"state":"cancelled"')
            ->and($response->getContent())->toContain('"can_cancel":false')
            ->and($response->getContent())->toContain('"can_reschedule":false');
    });

    /*
     * Hidden client-side is not refused. These post straight at the endpoints.
     */
    it('refuses a cancel posted directly at an already-cancelled booking', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon, ['status' => BookingStatus::Cancelled]);

        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertStatus(422);
    });

    it('refuses a reschedule posted directly at a booking that has been', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon, ['starts_at' => '2026-02-01 09:00:00', 'status' => BookingStatus::Completed]);

        $this->postJson(route('booking.manage.reschedule', $booking->public_token), [
            'starts_at' => CarbonImmutable::parse('2026-03-10 11:00', 'Europe/London')->utc()->toIso8601String(),
            'staff_id' => $salon['staff']->id,
        ])->assertStatus(422);

        expect($booking->fresh()->status)->toBe(BookingStatus::Completed);
    });

    it('refuses a cancel inside the window from taking a second bite', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertOk();
        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertStatus(422);

        expect(app(StripeGateway::class)->refunds)->toHaveCount(1);
    });
});

describe('the freed slot', function () {
    it('goes to the waitlist when a customer cancels', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        WaitlistEntry::factory()->create([
            'tenant_id' => $salon['tenant']->id,
            'service_id' => $salon['service']->id,
            'customer_id' => Customer::factory()->create(['tenant_id' => $salon['tenant']->id])->id,
        ]);

        $this->postJson(route('booking.manage.cancel', $booking->public_token))->assertOk();

        expect(SlotOffer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())
            ->toBeGreaterThan(0);
    });

    it('carries the deposit that was actually paid across a reschedule', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon, ['deposit_at_booking' => 750]);
        $to = CarbonImmutable::parse('2026-03-10 11:00:00', 'Europe/London')->utc();

        $this->postJson(route('booking.manage.reschedule', $booking->public_token), [
            'starts_at' => $to->toIso8601String(),
            'staff_id' => $salon['staff']->id,
        ])->assertOk();

        $fresh = $booking->fresh();

        expect($fresh->starts_at->eq($to))->toBeTrue()
            ->and($fresh->deposit_at_booking->amount)->toBe(750)
            ->and($fresh->deposit_status)->toBe(DepositStatus::Paid);
    });
});

describe('the throttle', function () {
    /*
     * Unauthenticated by design, so the limiter is the only thing between the
     * token space and somebody walking it. `booking-manage` is the same pattern
     * the public `.ics` feed uses.
     */
    it('caps how hard one link can be hammered', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon);
        $perToken = (int) config('booking_management.rate_limit_per_minute');

        for ($attempt = 0; $attempt < $perToken; $attempt++) {
            $this->get(route('booking.manage.show', $booking->public_token))->assertOk();
        }

        $this->get(route('booking.manage.show', $booking->public_token))->assertStatus(429);
    });

    /*
     * The one that matters. Every guess carries a different token, so a limiter
     * keyed by the token alone would put each guess in a bucket of its own and
     * never trip — which is what this route used to do.
     */
    it('caps a walk through the token space from one address', function () {
        $perIp = (int) config('booking_management.rate_limit_per_ip_per_minute');
        $blocked = false;

        for ($attempt = 0; $attempt <= $perIp + 1; $attempt++) {
            if ($this->get(route('booking.manage.show', Str::uuid()))->status() === 429) {
                $blocked = true;
                break;
            }
        }

        expect($blocked)->toBeTrue();
    });
});

describe('the salon code', function () {
    it('derives the corner code from the tenant address rather than inventing one', function () {
        $salon = manageTenant();
        $booking = manageBooking($salon);

        $this->get(route('booking.manage.show', $booking->public_token))
            ->assertOk()
            ->assertSee('EK · G74', false);
    });

    it('omits it entirely when there is no postcode to derive it from', function () {
        $salon = manageTenant();
        $salon['tenant']->forceFill(['postcode' => null])->save();
        $booking = manageBooking($salon);

        $this->get(route('booking.manage.show', $booking->public_token))
            ->assertOk()
            ->assertDontSee('EK ·', false);
    });
});
