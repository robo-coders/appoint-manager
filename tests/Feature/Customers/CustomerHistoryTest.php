<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\CustomerHistoryService;
use App\Services\Stripe\StripeGateway;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00', 'Europe/London'));
});

/**
 * @return array{tenant: Tenant, owner: User, staff: User, service: Service, customer: Customer}
 */
function aCustomerRecord(array $overrides = []): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London', 'country' => 'GB']);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Owner,
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::Staff,
        'name' => 'Erin Doyle',
        'is_bookable' => true,
    ]);

    $service = Service::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Full groom',
        'price' => 4200,
        'deposit_amount' => 1000,
        'duration_minutes' => 60,
    ]);

    $customer = Customer::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Claire Donnelly',
        'email' => 'claire@example.test',
        'phone' => '07700900114',
    ], $overrides));

    return compact('tenant', 'owner', 'staff', 'service', 'customer');
}

/**
 * @param  array{tenant: Tenant, staff: User, service: Service, customer: Customer}  $salon
 */
function aVisit(array $salon, string $day, BookingStatus $status, array $overrides = []): Booking
{
    $starts = CarbonImmutable::parse($day.' 09:30:00', 'Europe/London');

    return Booking::factory()->create(array_merge([
        'tenant_id' => $salon['tenant']->id,
        'staff_id' => $salon['staff']->id,
        'service_id' => $salon['service']->id,
        'customer_id' => $salon['customer']->id,
        'starts_at' => $starts->utc(),
        'ends_at' => $starts->addHour()->utc(),
        'status' => $status,
        'deposit_status' => DepositStatus::None,
        'price_at_booking' => 4200,
        'deposit_at_booking' => 0,
        'source' => BookingSource::Online,
    ], $overrides));
}

function recordProps(array $salon, array $query = []): AssertableInertia
{
    $page = null;

    actingAsTenant($salon['owner'])
        ->get(route('customers.show', $salon['customer']->id).($query === [] ? '' : '?'.http_build_query($query)))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $inertia) use (&$page) {
            $page = $inertia;
        });

    return $page;
}

describe('the figures', function () {
    it('counts kept and missed visits and leaves cancellations out of both', function () {
        $salon = aCustomerRecord();

        aVisit($salon, '2026-03-12', BookingStatus::Completed);
        aVisit($salon, '2026-05-09', BookingStatus::Completed);
        aVisit($salon, '2026-06-14', BookingStatus::NoShow);
        aVisit($salon, '2026-07-01', BookingStatus::Cancelled);
        aVisit($salon, '2026-07-02', BookingStatus::Declined);

        recordProps($salon)
            ->component('Customers/Show')
            ->where('stats.visits', 3)
            ->where('stats.no_shows', 1)
            ->where('attendance.kept', 2)
            ->where('attendance.booked', 3)
            ->where('attendance.percentage', 67);
    });

    it('counts a deposit the salon kept towards lifetime value and a refunded one not at all', function () {
        $salon = aCustomerRecord();

        aVisit($salon, '2026-03-12', BookingStatus::Completed, [
            'deposit_status' => DepositStatus::Paid,
            'deposit_at_booking' => 1000,
        ]);
        aVisit($salon, '2026-06-14', BookingStatus::NoShow, [
            'deposit_status' => DepositStatus::Paid,
            'deposit_at_booking' => 1000,
        ]);
        aVisit($salon, '2026-07-01', BookingStatus::Cancelled, [
            'deposit_status' => DepositStatus::Refunded,
            'deposit_at_booking' => 1000,
        ]);

        recordProps($salon)
            ->where('stats.lifetime_value.amount', 5200)
            ->where('stats.lifetime_value.formatted', '£52.00');
    });

    it('averages the gap between settled visits only', function () {
        $salon = aCustomerRecord();

        aVisit($salon, '2026-01-01', BookingStatus::Completed);
        aVisit($salon, '2026-01-11', BookingStatus::Completed);
        aVisit($salon, '2026-01-31', BookingStatus::NoShow);
        aVisit($salon, '2026-02-15', BookingStatus::Cancelled);

        recordProps($salon)->where('stats.average_gap_days', 15);
    });

    it('has no average gap and no attendance percentage for a single visit', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-01-01', BookingStatus::Completed);

        recordProps($salon)
            ->where('stats.average_gap_days', null)
            ->where('attendance.percentage', 100)
            ->where('cadence', fn ($cadence) => count($cadence) === 1 && $cadence[0]['gap_days'] === null);
    });

    it('names the next confirmed appointment, and says nothing is booked when there is none', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-03-12', BookingStatus::Completed);

        recordProps($salon)->where('stats.next_visit', null);

        aVisit($salon, '2026-09-24', BookingStatus::Confirmed);

        recordProps($salon)
            ->where('stats.next_visit.day_label', '24 Sep')
            ->where('stats.next_visit.time', '09:30')
            ->where('stats.next_visit.service_name', 'Full groom');
    });

    it('reads the subject descriptor out of the vertical fields rather than hardcoding a breed', function () {
        $salon = aCustomerRecord();

        app(TenantContext::class)->set($salon['tenant']);
        Subject::query()->create([
            'customer_id' => $salon['customer']->id,
            'name' => 'Nala',
            'attributes' => ['breed' => 'cockapoo', 'coat' => 'medium coat', 'notes' => 'nervous with the dryer'],
        ]);
        app(TenantContext::class)->clear();

        recordProps($salon)
            ->where('customer.subjects.0.name', 'Nala')
            ->where('customer.subjects.0.descriptor', 'cockapoo, medium coat');
    });
});

describe('the deposit behaviour label', function () {
    it('says no deposit is required when none ever was', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-03-12', BookingStatus::Completed);

        recordProps($salon)->where(
            'attendance.deposit_behaviour',
            CustomerHistoryService::DEPOSIT_NOT_REQUIRED,
        );
    });

    it('says the deposit is always paid when every required one was', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-03-12', BookingStatus::Completed, [
            'deposit_status' => DepositStatus::Paid,
            'deposit_at_booking' => 1000,
        ]);
        aVisit($salon, '2026-05-09', BookingStatus::Cancelled, [
            'deposit_status' => DepositStatus::Refunded,
            'deposit_at_booking' => 1000,
        ]);

        recordProps($salon)->where(
            'attendance.deposit_behaviour',
            CustomerHistoryService::DEPOSIT_ALWAYS_PAID,
        );
    });

    it('counts a forfeit only where the money was kept', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-06-14', BookingStatus::NoShow, [
            'deposit_status' => DepositStatus::Paid,
            'deposit_at_booking' => 1000,
        ]);
        aVisit($salon, '2026-08-02', BookingStatus::Cancelled, [
            'deposit_status' => DepositStatus::Refunded,
            'deposit_at_booking' => 1000,
        ]);

        recordProps($salon)->where(
            'attendance.deposit_behaviour',
            CustomerHistoryService::DEPOSIT_FORFEITED_ONCE,
        );

        aVisit($salon, '2026-08-30', BookingStatus::NoShow, [
            'deposit_status' => DepositStatus::Paid,
            'deposit_at_booking' => 1000,
        ]);

        recordProps($salon)->where(
            'attendance.deposit_behaviour',
            sprintf(CustomerHistoryService::DEPOSIT_FORFEITED_MANY, 2),
        );
    });
});

describe('the status badge', function () {
    it('shows nothing at all until there is enough history to mean anything', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-03-12', BookingStatus::Completed);
        aVisit($salon, '2026-05-09', BookingStatus::Completed);

        recordProps($salon)->where('badge', null);
    });

    it('calls a customer reliable at exactly the minimum visit count with no misses', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-01-12', BookingStatus::Completed);
        aVisit($salon, '2026-02-09', BookingStatus::Completed);
        aVisit($salon, '2026-03-09', BookingStatus::Completed);

        recordProps($salon)
            ->where('badge.label', CustomerHistoryService::BADGE_RELIABLE)
            ->where('badge.tone', CustomerHistoryService::TONE_NEUTRAL);
    });

    it('withholds the reliable label at a no-show rate exactly on the threshold', function () {
        config(['customers.reliable_no_show_threshold' => 0.25, 'customers.min_visits_for_reliability_label' => 4]);

        $salon = aCustomerRecord();
        aVisit($salon, '2024-01-12', BookingStatus::Completed);
        aVisit($salon, '2024-02-09', BookingStatus::Completed);
        aVisit($salon, '2024-03-09', BookingStatus::Completed);
        aVisit($salon, '2024-04-09', BookingStatus::NoShow);

        recordProps($salon)->where('badge', null);
    });

    it('gives the reliable label just under the threshold', function () {
        config(['customers.reliable_no_show_threshold' => 0.25, 'customers.min_visits_for_reliability_label' => 4]);

        $salon = aCustomerRecord();
        aVisit($salon, '2024-01-12', BookingStatus::Completed);
        aVisit($salon, '2024-02-09', BookingStatus::Completed);
        aVisit($salon, '2024-03-09', BookingStatus::Completed);
        aVisit($salon, '2024-04-09', BookingStatus::Completed);
        aVisit($salon, '2024-05-09', BookingStatus::NoShow);

        recordProps($salon)->where('badge.label', CustomerHistoryService::BADGE_RELIABLE);
    });

    it('watches a customer whose misses meet the count inside the window', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-07-14', BookingStatus::NoShow);
        aVisit($salon, '2026-08-02', BookingStatus::NoShow);

        recordProps($salon)
            ->where('badge.label', sprintf(CustomerHistoryService::BADGE_WATCH, 2))
            ->where('badge.tone', CustomerHistoryService::TONE_WATCH);
    });

    it('does not watch a customer whose misses are older than the window', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-01-14', BookingStatus::NoShow);
        aVisit($salon, '2026-02-02', BookingStatus::NoShow);
        aVisit($salon, '2026-08-30', BookingStatus::Completed);

        recordProps($salon)->where('badge', null);
    });
});

describe('the suggested rule', function () {
    it('fires exactly at the trigger count inside the window', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-08-02', BookingStatus::NoShow);

        recordProps($salon)->where('suggestedRule', null);

        aVisit($salon, '2026-08-30', BookingStatus::NoShow);

        recordProps($salon)
            ->where('suggestedRule.no_show_count', 2)
            ->where('suggestedRule.window_months', 3)
            ->where('suggestedRule.message', sprintf(CustomerHistoryService::SUGGESTED_RULE_MESSAGE, 2, 3));
    });

    it('does not fire for misses outside the window', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-01-14', BookingStatus::NoShow);
        aVisit($salon, '2026-02-02', BookingStatus::NoShow);

        recordProps($salon)->where('suggestedRule', null);
    });

    it('stays dismissed once dismissed', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-08-02', BookingStatus::NoShow);
        aVisit($salon, '2026-08-30', BookingStatus::NoShow);

        actingAsTenant($salon['owner'])
            ->post(route('customers.dismiss-rule', $salon['customer']->id))
            ->assertRedirect();

        expect($salon['customer']->fresh()->suggested_rule_dismissed_at)->not->toBeNull();

        recordProps($salon)->where('suggestedRule', null);
    });

    it('stops suggesting once the override is on', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-08-02', BookingStatus::NoShow);
        aVisit($salon, '2026-08-30', BookingStatus::NoShow);

        actingAsTenant($salon['owner'])
            ->post(route('customers.require-full-payment', $salon['customer']->id))
            ->assertRedirect();

        expect($salon['customer']->fresh()->requires_full_payment_override)->toBeTrue();

        recordProps($salon)
            ->where('suggestedRule', null)
            ->where('customer.requires_full_payment', true);
    });

    it('takes the whole price up front once the override is on', function () {
        Mail::fake();

        $tenant = Tenant::factory()->create([
            'timezone' => 'Europe/London',
            'country' => 'GB',
            'stripe_account_id' => 'acct_history',
            'stripe_onboarding_complete' => true,
        ]);
        app(StripeGateway::class)->completeAccount('acct_history');

        $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true, 'is_active' => true]);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'price' => 4200,
            'deposit_amount' => 1000,
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
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

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => '+447700900000',
            'requires_full_payment_override' => true,
        ]);

        $bookings = app(BookingService::class);

        expect($bookings->needsDeposit($tenant, $service, BookingSource::Online, $customer))->toBeTrue()
            ->and($bookings->upfrontAmount($service, $customer))->toBe(4200);

        $booking = $bookings->create(
            $tenant,
            $service,
            $staff,
            $customer,
            CarbonImmutable::parse('2026-09-15 09:00:00', 'Europe/London')->utc(),
            BookingSource::Online,
        );

        expect($booking->deposit_at_booking->amount)->toBe(4200)
            ->and($booking->deposit_status)->toBe(DepositStatus::Required);
    });
});

describe('the empty record', function () {
    it('returns a whole zero-state payload with no division by nothing', function () {
        $salon = aCustomerRecord();

        recordProps($salon)
            ->where('stats.visits', 0)
            ->where('stats.no_shows', 0)
            ->where('stats.lifetime_value.amount', 0)
            ->where('stats.average_gap_days', null)
            ->where('stats.next_visit', null)
            ->where('attendance.percentage', null)
            ->where('attendance.strip', [])
            ->where('attendance.books_through', null)
            ->where('attendance.usual_slot', null)
            ->where('badge', null)
            ->where('suggestedRule', null)
            ->where('cadence', [])
            ->where('ledger.total', 0)
            ->where('attendance.summary', 'Nothing booked yet, so there is nothing to measure.');
    });

    it('has no attendance denominator for a customer who only ever cancelled', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-03-12', BookingStatus::Cancelled);
        aVisit($salon, '2026-05-09', BookingStatus::Declined);

        recordProps($salon)
            ->where('attendance.percentage', null)
            ->where('stats.visits', 0)
            ->where('ledger.total', 2)
            ->where(
                'attendance.summary',
                'Nothing kept and nothing missed so far — every booking was cancelled or is still to come.',
            );
    });
});

describe('the notes', function () {
    it('saves the note with who wrote it and when', function () {
        $salon = aCustomerRecord();

        actingAsTenant($salon['owner'])
            ->patch(route('customers.notes.update', $salon['customer']->id), [
                'notes' => 'Nervous with the dryer — low setting, radio off.',
            ])
            ->assertRedirect();

        $customer = $salon['customer']->fresh();

        expect($customer->notes)->toBe('Nervous with the dryer — low setting, radio off.')
            ->and($customer->notes_updated_by)->toBe($salon['owner']->id)
            ->and($customer->notes_updated_at)->not->toBeNull();

        recordProps($salon)
            ->where('notes.text', 'Nervous with the dryer — low setting, radio off.')
            ->where('notes.editor_name', $salon['owner']->name)
            ->where('notes.updated_at', '10 Sep 2026');
    });

    it('is readable and writable by a staff member as well as the owner', function () {
        $salon = aCustomerRecord();

        actingAsTenant($salon['staff'])
            ->patch(route('customers.notes.update', $salon['customer']->id), ['notes' => 'Allow ten more minutes.'])
            ->assertRedirect();

        expect($salon['customer']->fresh()->notes)->toBe('Allow ten more minutes.');
    });

    it('rejects an over-long note and leaves the stored one alone', function () {
        $salon = aCustomerRecord();
        $salon['customer']->forceFill(['notes' => 'Keep me.'])->save();

        actingAsTenant($salon['owner'])
            ->patch(
                route('customers.notes.update', $salon['customer']->id),
                ['notes' => str_repeat('a', 2001)],
                ['Accept' => 'application/json'],
            )
            ->assertStatus(422)
            ->assertJson(fn (AssertableJson $json) => $json->has('errors.notes')->etc());

        expect($salon['customer']->fresh()->notes)->toBe('Keep me.');
    });

    it('clears the note when it is emptied rather than storing whitespace', function () {
        $salon = aCustomerRecord();
        $salon['customer']->forceFill(['notes' => 'Old note.'])->save();

        actingAsTenant($salon['owner'])
            ->patch(route('customers.notes.update', $salon['customer']->id), ['notes' => '   '])
            ->assertRedirect();

        expect($salon['customer']->fresh()->notes)->toBeNull();
    });
});

describe('the ledger', function () {
    it('pages at the configured size, newest first, and every row resolves to its booking', function () {
        config(['customers.ledger_page_size' => 3]);

        $salon = aCustomerRecord();
        $days = ['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05', '2026-06-05', '2026-07-05'];
        $ids = [];

        foreach ($days as $day) {
            $ids[$day] = aVisit($salon, $day, BookingStatus::Completed)->id;
        }

        recordProps($salon)
            ->where('ledger.total', 7)
            ->where('ledger.per_page', 3)
            ->where('ledger.last_page', 3)
            ->where('ledger.data.0.id', $ids['2026-07-05'])
            ->where('ledger.data.0.starts_at_local', '2026-07-05 09:30')
            ->where('ledger.data.0.status', BookingStatus::Completed->value)
            ->where('ledger.data.0.outcome', CustomerHistoryService::OUTCOME_ATTENDED)
            ->where('ledger.data.0.paid.formatted', '£42.00')
            ->where('ledger.data', fn ($rows) => count($rows) === 3);

        recordProps($salon, ['visits' => 3])
            ->where('ledger.current_page', 3)
            ->where('ledger.data.0.id', $ids['2026-01-05'])
            ->where('ledger.data', fn ($rows) => count($rows) === 1);
    });

    it('expands to one page when asked to show them all', function () {
        config(['customers.ledger_page_size' => 3, 'customers.ledger_expanded_page_size' => 50]);

        $salon = aCustomerRecord();

        foreach (['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05'] as $day) {
            aVisit($salon, $day, BookingStatus::Completed);
        }

        recordProps($salon, ['visits_all' => 1])
            ->where('ledgerExpanded', true)
            ->where('ledger.per_page', 50)
            ->where('ledger.last_page', 1)
            ->where('ledger.data', fn ($rows) => count($rows) === 4);
    });

    it('carries the exact time and the subject name, not the date alone', function () {
        $salon = aCustomerRecord();

        app(TenantContext::class)->set($salon['tenant']);
        $subject = Subject::query()->create([
            'customer_id' => $salon['customer']->id,
            'name' => 'Nala',
            'attributes' => ['breed' => 'cockapoo'],
        ]);
        app(TenantContext::class)->clear();

        aVisit($salon, '2026-03-05', BookingStatus::Completed, ['subject_id' => $subject->id]);

        recordProps($salon)
            ->where('ledger.data.0.starts_at_local', '2026-03-05 09:30')
            ->where('ledger.data.0.subject_name', 'Nala')
            ->where('ledger.data.0.staff_name', 'Erin Doyle');
    });
});

describe('who may look', function () {
    it('answers 404 for another salon\'s customer rather than confirming it exists', function () {
        $salon = aCustomerRecord();
        $other = aCustomerRecord();

        actingAsTenant($salon['owner'])
            ->get(route('customers.show', $other['customer']->id))
            ->assertNotFound();
    });

    it('refuses a write against another salon\'s customer the same way', function () {
        $salon = aCustomerRecord();
        $other = aCustomerRecord();

        actingAsTenant($salon['owner'])
            ->patch(route('customers.notes.update', $other['customer']->id), ['notes' => 'no'])
            ->assertNotFound();

        actingAsTenant($salon['owner'])
            ->post(route('customers.require-full-payment', $other['customer']->id))
            ->assertNotFound();

        expect($other['customer']->fresh()->requires_full_payment_override)->toBeFalse();
    });
});

describe('the way in from the customers list', function () {
    it('sends "Their bookings" to the customer record, not to the old filtered bookings URL', function () {
        $source = file_get_contents(resource_path('js/Pages/Customers/Index.vue'));

        expect($source)->toContain('Their bookings')
            ->and($source)->not->toContain("route('bookings.index'), { customer:")
            ->and($source)->toContain("route('customers.show', Number(row.id))");
    });

    it('has no customer filter left on the bookings list to route to', function () {
        $salon = aCustomerRecord();
        aVisit($salon, '2026-03-05', BookingStatus::Completed);

        $other = Customer::factory()->create(['tenant_id' => $salon['tenant']->id]);

        actingAsTenant($salon['owner'])
            ->get(route('bookings.index', ['customer' => $other->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Bookings/Index')
                ->where('bookings.total', 1)
                ->has('filters', fn ($filters) => $filters
                    ->hasAll(['status', 'from', 'to', 'sort', 'direction'])
                    ->etc()));
    });
});
