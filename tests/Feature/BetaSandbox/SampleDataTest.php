<?php

use App\BetaSandbox\SampleData;
use App\Enums\BookingStatus;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Sms\RecordingSmsGateway;
use App\Services\Sms\SmsGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * "Load sample data". See BETA_SANDBOX.md.
 *
 * The three things worth asserting are the three the brief names: the shape is
 * right, running it twice is safe, and nothing is sent to anybody. The last one
 * is not a nicety — the whole feature invents phone numbers and then makes the
 * product's own automation happen to them.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London'));
    Mail::fake();
});

it('fills an empty shop with customers, a diary and a waitlist', function () {
    $salon = aBetaSalon();

    $counts = app(SampleData::class)->load($salon['tenant']);

    expect($counts['customers'])->toBeGreaterThan(0);
    expect($counts['bookings'])->toBeGreaterThan(0);

    $tenantId = $salon['tenant']->id;

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(24);
    expect(Subject::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBeGreaterThan(24);
    expect(WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(4);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBeGreaterThan(50);
});

it('spreads bookings across the statuses an owner needs to see', function () {
    $salon = aBetaSalon();

    app(SampleData::class)->load($salon['tenant']);

    $statuses = Booking::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->pluck('status')
        ->map(fn ($status) => $status instanceof BookingStatus ? $status->value : $status)
        ->unique()
        ->values()
        ->all();

    // Completed, cancelled and no-show are what make the dashboard's figures
    // mean anything; pending is what a fast-forward has something to release.
    expect($statuses)->toContain(BookingStatus::Completed->value);
    expect($statuses)->toContain(BookingStatus::Cancelled->value);
    expect($statuses)->toContain(BookingStatus::NoShow->value);
    expect($statuses)->toContain(BookingStatus::Confirmed->value);
    expect($statuses)->toContain(BookingStatus::Pending->value);
});

it('puts real history behind some customers and real appointments ahead', function () {
    $salon = aBetaSalon();

    app(SampleData::class)->load($salon['tenant']);

    $tenantId = $salon['tenant']->id;

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('starts_at', '<', now())->count())
        ->toBeGreaterThan(0);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('starts_at', '>', now())->count())
        ->toBeGreaterThan(0);

    // "Some with booking history" — at least one customer with more than one.
    $repeat = Booking::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->selectRaw('customer_id, count(*) as total')
        ->groupBy('customer_id')
        ->havingRaw('count(*) > 1')
        ->count();

    expect($repeat)->toBeGreaterThan(0);
});

it('replaces rather than accumulates when it is run again', function () {
    $salon = aBetaSalon();
    $sample = app(SampleData::class);

    $first = $sample->load($salon['tenant']);
    $second = $sample->load($salon['tenant']);

    expect($second)->toBe($first);

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(24);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())
        ->toBe($first['bookings']);
});

it('sends nothing to anybody while it loads', function () {
    $salon = aBetaSalon();
    $sms = app(SmsGateway::class);

    app(SampleData::class)->load($salon['tenant']);

    expect($sms)->toBeInstanceOf(RecordingSmsGateway::class);
    expect($sms->sent)->toBe([]);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('gives every invented customer a phone number that belongs to nobody', function () {
    $salon = aBetaSalon();

    app(SampleData::class)->load($salon['tenant']);

    $phones = Customer::withoutGlobalScopes()
        ->where('tenant_id', $salon['tenant']->id)
        ->pluck('phone');

    // Ofcom reserves 07700 900000-900999 for drama; no handset is ever on one.
    foreach ($phones as $phone) {
        expect($phone)->toStartWith('07700900');
    }
});

it('leaves another salon completely untouched', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    app(SampleData::class)->load($mine['tenant']);

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count())->toBe(0);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count())->toBe(0);
});

it('refuses in plain words when there is nothing to build a diary from', function () {
    $tenant = Tenant::factory()->create(['is_beta' => true])->fresh();

    // A salon with no services and nobody bookable. The honest answer is a
    // sentence naming what to do, not an empty diary.
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => false]);

    actingAsTenant($owner)
        ->post(route('beta-sandbox.sample-data'))
        ->assertRedirect()
        ->assertSessionHasErrors('sandbox');

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('enrols one regular partway through a loyalty package when loyalty is on', function () {
    $salon = aBetaSalon();
    $tenant = $salon['tenant'];

    $settings = $tenant->settings ?? [];
    $settings['loyalty']['enabled'] = true;
    $tenant->forceFill(['settings' => $settings])->save();

    $package = LoyaltyPackage::factory()->create([
        'tenant_id' => $tenant->id,
        'sessions_required' => 5,
        'is_active' => true,
    ]);

    $counts = app(SampleData::class)->load($tenant->fresh());

    expect($counts['loyalty'])->toBe(1);

    $enrolment = LoyaltyEnrolment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($enrolment->loyalty_package_id)->toBe($package->id);
    expect($enrolment->stamps_used)->toBeGreaterThan(0);
    expect($enrolment->stamps_used)->toBeLessThan(5);
});

it('creates no loyalty rows for a shop that has not switched loyalty on', function () {
    $salon = aBetaSalon();

    $counts = app(SampleData::class)->load($salon['tenant']);

    expect($counts['loyalty'])->toBe(0);
    expect(LoyaltyEnrolment::withoutGlobalScopes()->where('tenant_id', $salon['tenant']->id)->count())->toBe(0);
});

it('keeps the shop\'s own staff, services and hours rather than inventing any', function () {
    $salon = aBetaSalon();
    $tenantId = $salon['tenant']->id;

    $staffBefore = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id')->all();
    $servicesBefore = Service::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id')->all();
    $rulesBefore = AvailabilityRule::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

    app(SampleData::class)->load($salon['tenant']);

    expect(User::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id')->all())->toBe($staffBefore);
    expect(Service::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id')->all())->toBe($servicesBefore);
    expect(AvailabilityRule::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe($rulesBefore);
});
