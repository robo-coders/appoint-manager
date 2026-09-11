<?php

use App\BetaSandbox\FastForward;
use App\BetaSandbox\SampleData;
use App\BetaSandbox\SandboxReset;
use App\BetaSandbox\SandboxTables;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\LoyaltyPackage;
use App\Models\Message;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\TimeOff;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/London'));
    Mail::fake();
});

it('empties the shop of everything transactional', function () {
    $salon = aBetaSalon();
    $tenantId = $salon['tenant']->id;

    app(SampleData::class)->load($salon['tenant']);

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBeGreaterThan(0);

    app(SandboxReset::class)->run($salon['tenant']);

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(0);
    expect(Subject::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(0);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(0);
    expect(WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(0);
    expect(Message::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(0);
    expect(LoyaltyEnrolment::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(0);
    expect(DB::table('slot_offers')->where('tenant_id', $tenantId)->count())->toBe(0);
    expect(DB::table('rebook_sends')->where('tenant_id', $tenantId)->count())->toBe(0);
});

it('keeps the shop itself, and everything the owner set up', function () {
    $salon = aBetaSalon();
    $tenant = $salon['tenant'];
    $tenantId = $tenant->id;

    TimeOff::factory()->create([
        'tenant_id' => $tenantId,
        'user_id' => $salon['staff']->id,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addDay(),
    ]);

    $package = LoyaltyPackage::factory()->create(['tenant_id' => $tenantId]);

    app(SampleData::class)->load($tenant);
    app(SandboxReset::class)->run($tenant);

    expect(Tenant::query()->find($tenantId))->not->toBeNull();
    expect(User::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBeGreaterThan(0);

    expect(Service::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBeGreaterThan(0);
    expect(AvailabilityRule::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBeGreaterThan(0);
    expect(TimeOff::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(1);
    expect(LoyaltyPackage::withoutGlobalScopes()->whereKey($package->id)->exists())->toBeTrue();

    $fresh = Tenant::query()->find($tenantId);
    expect($fresh->subscription_status)->toBe($tenant->subscription_status);
    expect($fresh->trial_ends_at->toDateTimeString())->toBe($tenant->trial_ends_at->toDateTimeString());
});

it('touches no other salon', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    app(SampleData::class)->load($mine['tenant']);
    app(SampleData::class)->load($theirs['tenant']);

    $before = Booking::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count();

    app(SandboxReset::class)->run($mine['tenant']);

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count())->toBe($before);
    expect(Customer::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count())->toBe(24);
});

it('leaves a working shop that can be filled again immediately', function () {
    $salon = aBetaSalon();

    app(SampleData::class)->load($salon['tenant']);
    app(SandboxReset::class)->run($salon['tenant']);

    $counts = app(SampleData::class)->load($salon['tenant']);

    expect($counts['customers'])->toBe(24);
    expect($counts['bookings'])->toBeGreaterThan(50);

    actingAsTenant($salon['staff'])->get(route('dashboard'))->assertOk();
    actingAsTenant($salon['staff'])->get(route('customers.index'))->assertOk();
    actingAsTenant($salon['staff'])->get(route('diary.index'))->assertOk();
});

it('rolls back completely when the wipe fails partway through', function () {
    $salon = aBetaSalon();
    $tenantId = $salon['tenant']->id;

    app(SampleData::class)->load($salon['tenant']);

    $customers = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();
    $bookings = Booking::withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

    DB::listen(function ($query) {
        if (str_contains($query->sql, 'delete from `customers`')) {
            throw new RuntimeException('Simulated failure partway through the wipe.');
        }
    });

    expect(fn () => app(SandboxReset::class)->run($salon['tenant']))
        ->toThrow(RuntimeException::class, 'Simulated failure');

    expect(Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe($customers);
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe($bookings);
    expect(WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBe(4);
});

it('empties a shop that has been lived in, not just one that was seeded', function () {
    $salon = aBetaSalon();
    $tenantId = $salon['tenant']->id;

    app(SampleData::class)->load($salon['tenant']);
    app(FastForward::class)->run($salon['tenant'], 'week');

    expect(DB::table('slot_offers')->where('tenant_id', $tenantId)->count())->toBeGreaterThan(0);
    expect(Message::withoutGlobalScopes()->where('tenant_id', $tenantId)->count())->toBeGreaterThan(0);

    app(SandboxReset::class)->run($salon['tenant']);

    foreach (SandboxTables::transactional() as $table) {
        expect(DB::table($table)->where('tenant_id', $tenantId)->count())
            ->toBe(0, $table.' should be empty after a reset');
    }
});

it('reports what it removed so the screen can say so in words', function () {
    $salon = aBetaSalon();

    app(SampleData::class)->load($salon['tenant']);

    $removed = app(SandboxReset::class)->run($salon['tenant']);

    expect($removed['customers'])->toBe(24);
    expect($removed['bookings'])->toBeGreaterThan(50);
});

it('leaves every count on the bookings page reading zero, on the reload too', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    app(SampleData::class)->load($mine['tenant']);
    app(SampleData::class)->load($theirs['tenant']);

    app(SandboxReset::class)->run($mine['tenant']);

    $zeroed = fn ($page) => $page
        ->component('Bookings/Index')
        ->has('bookings.data', 0)
        ->where('counts.total', 0)
        ->where('counts.confirmed', 0)
        ->where('counts.pending', 0)
        ->where('counts.cancelled', 0)
        ->where('counts.declined', 0)
        ->where('counts.completed', 0)
        ->where('counts.no_show', 0);

    actingAsTenant($mine['staff'])->get(route('bookings.index'))->assertOk()->assertInertia($zeroed);
    actingAsTenant($mine['staff'])->get(route('bookings.index'))->assertOk()->assertInertia($zeroed);

    expect(Booking::withoutGlobalScopes()->where('tenant_id', $theirs['tenant']->id)->count())->toBeGreaterThan(0);
});

it('leaves the other list screens counting zero too, with a full salon next door', function () {
    $mine = aBetaSalon();
    $theirs = aBetaSalon();

    app(SampleData::class)->load($mine['tenant']);
    app(SampleData::class)->load($theirs['tenant']);

    app(SandboxReset::class)->run($mine['tenant']);

    actingAsTenant($mine['staff'])->get(route('waitlist.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('entries', 0));

    actingAsTenant($mine['staff'])->get(route('customers.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('customers.total', 0)->has('customers.data', 0));

    actingAsTenant($mine['staff'])->get(route('overdue.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.count', 0)->has('rows', 0));

    actingAsTenant($mine['staff'])->get(route('bookings.index'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('navCounts.bookings', 0)
            ->where('navCounts.customers', 0)
            ->where('navCounts.overdue', 0)
            ->where('navCounts.waitlist', 0));
});
