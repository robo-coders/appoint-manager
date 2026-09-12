<?php

use App\Enums\MessageType;
use App\Enums\PreferredTime;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use App\Models\WaitlistEntry;
use App\Services\Waitlist\WaitlistOfferer;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00:00', 'Europe/London'));
});

function aGarage(array $overrides = []): array
{
    return aSalon(array_merge_recursive([
        'tenant' => ['type' => 'garage', 'slug' => 'pit-lane-motors', 'name' => 'Pit Lane Motors'],
    ], $overrides));
}

it('ships a garage definition every consumer can read', function () {
    $garage = Vertical::query()->where('key', 'garage')->firstOrFail();

    expect($garage->subject_fields)->not->toBeEmpty()
        ->and($garage->default_services)->not->toBeEmpty();

    foreach ($garage->subject_fields as $field) {
        expect($field)->toHaveKeys(['key', 'label', 'type', 'required'])
            ->and($field['key'])->toMatch('/^[a-z0-9_]+$/')
            ->and($field['type'])->toBeIn(['text', 'textarea', 'select']);
    }

    foreach ($garage->default_services as $service) {
        expect($service)->toHaveKeys(['name', 'duration_minutes', 'price', 'deposit_amount'])
            ->and($service['deposit_amount'])->toBeLessThanOrEqual($service['price']);
    }

    expect(array_column($garage->subject_fields, 'key'))->toBe(['vehicle_registration', 'make_model', 'mileage']);
});

it('describes a garage by what it services, not by the trade name', function () {
    $garage = Vertical::query()->where('key', 'garage')->firstOrFail();

    expect($garage->subject_singular)->toBe('vehicle')
        ->and($garage->subject_plural)->toBe('vehicles')
        ->and($garage->business_noun)->toBe('garage')
        ->and($garage->note())->toBe('vehicles · per visit');
});

it('offers garage on the signup form without a change to the form itself', function () {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(function ($page) {
            $types = collect($page->toArray()['props']['businessTypes']);
            $garage = $types->firstWhere('value', 'garage');

            expect($garage)->not->toBeNull()
                ->and($garage['label'])->toBe('Garage')
                ->and($garage['note'])->toBe('vehicles · per visit');
        });
});

it('lets a garage owner price an MOT with no deposit and a service with one', function () {
    $garage = Vertical::query()->where('key', 'garage')->firstOrFail();
    $deposits = array_column($garage->default_services, 'deposit_amount', 'name');

    expect($deposits['MOT'])->toBe(0)
        ->and($deposits['Oil change / service'])->toBeGreaterThan(0);
});

it('asks a garage customer for the vehicle, not for a breed', function () {
    ['tenant' => $tenant, 'service' => $service] = aGarage();

    $page = $this->get(route('public.booking.show', $tenant->slug))->assertOk();

    $page->assertSee('Vehicle registration', false)
        ->assertSee('vehicle_registration', false)
        ->assertSee('make_model', false)
        ->assertDontSee('Breed', false)
        ->assertDontSee('breed', false);

    expect($service)->not->toBeNull();
});

it('requires the vehicle fields a garage marked required', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aGarage();

    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London');

    $this->postJson(route('public.booking.store', $tenant->slug), [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts->toIso8601String(),
        'name' => 'Alex Reed',
        'email' => 'alex@example.com',
        'phone' => '07700900000',
        'subject_name' => 'The Focus',
        'subject_attributes' => ['mileage' => '82000'],
    ])->assertJsonValidationErrors([
        'subject_attributes.vehicle_registration',
        'subject_attributes.make_model',
    ]);
});

it('books a garage job once the vehicle details are given', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aGarage();

    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London');

    $this->postJson(route('public.booking.store', $tenant->slug), [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts->toIso8601String(),
        'name' => 'Alex Reed',
        'email' => 'alex@example.com',
        'phone' => '07700900000',
        'subject_name' => 'The Focus',
        'subject_attributes' => [
            'vehicle_registration' => 'AB12 CDE',
            'make_model' => 'Ford Focus',
            'mileage' => '82000',
        ],
    ])->assertCreated();

    $subject = Subject::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    expect($subject->attributes['vehicle_registration'])->toBe('AB12 CDE')
        ->and($subject->attributes['make_model'])->toBe('Ford Focus');
});

it('texts a garage waitlist offer with no grooming language in it', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aGarage();

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
        'phone' => '07700900111',
        'email' => 'ada@example.com',
    ]);

    WaitlistEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'preferred_days' => [],
        'preferred_times' => PreferredTime::Any,
        'is_active' => true,
    ]);

    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    app(WaitlistOfferer::class)->offer($tenant, $service, $staff, $starts, $starts->addMinutes(45));

    $body = Message::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('type', MessageType::WaitlistOffer->value)
        ->sole()
        ->body;

    expect($body)->toContain('Pit Lane Motors')
        ->and($body)->toContain('a slot is free')
        ->and(strtolower($body))->not->toContain('dog')
        ->and(strtolower($body))->not->toContain('pet')
        ->and(strtolower($body))->not->toContain('groom')
        ->and(strtolower($body))->not->toContain('salon');
});

it('fills a 45 minute MOT slot from the waitlist the same way a 90 minute groom is filled', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aGarage([
        'service' => ['duration_minutes' => 45],
    ]);

    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'phone' => '07700900222']);

    WaitlistEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'preferred_days' => [],
        'preferred_times' => PreferredTime::Any,
        'is_active' => true,
    ]);

    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London')->utc();

    $sent = app(WaitlistOfferer::class)->offer($tenant, $service, $staff, $starts, $starts->addMinutes(45));

    expect($sent)->toBe(1);
});

it('shows a garage customer their vehicle details on the customer record', function () {
    ['tenant' => $tenant, 'staff' => $staff, 'service' => $service] = aGarage();

    $starts = CarbonImmutable::parse('2026-03-10 09:00:00', 'Europe/London');

    $this->postJson(route('public.booking.store', $tenant->slug), [
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => $starts->toIso8601String(),
        'name' => 'Alex Reed',
        'email' => 'alex@example.com',
        'phone' => '07700900000',
        'subject_name' => 'The Focus',
        'subject_attributes' => [
            'vehicle_registration' => 'AB12 CDE',
            'make_model' => 'Ford Focus',
            'mileage' => '82000',
        ],
    ])->assertCreated();

    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
    $customer = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

    actingAsTenant($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customer.subjects.0.descriptor', 'AB12 CDE, Ford Focus, 82000'));
});

it('normalises a definition written straight to the model, so no path can store a broken one', function () {
    $vertical = Vertical::query()->create([
        'key' => 'valeting',
        'label' => 'Valeting',
        'business_noun' => 'unit',
        'subject_singular' => 'vehicle',
        'subject_plural' => 'vehicles',
        'subject_fields' => [
            ['label' => 'Vehicle registration', 'required' => true],
            ['label' => 'Paint colour', 'required' => false],
        ],
        'default_services' => [
            ['name' => 'Full valet', 'price' => 4500],
        ],
    ]);

    expect($vertical->subject_fields[0])->toMatchArray([
        'key' => 'vehicle_registration',
        'type' => 'text',
        'required' => true,
    ])
        ->and($vertical->subject_fields[1]['key'])->toBe('paint_colour')
        ->and($vertical->default_services[0])->toMatchArray([
            'name' => 'Full valet',
            'price' => 4500,
            'deposit_amount' => 0,
            'duration_minutes' => 60,
        ]);
});

it('never lets a default deposit exceed the price it is taken against', function () {
    $vertical = Vertical::query()->create([
        'key' => 'bodyshop',
        'label' => 'Bodyshop',
        'subject_singular' => 'vehicle',
        'subject_plural' => 'vehicles',
        'default_services' => [
            ['name' => 'Panel respray', 'price' => 12000, 'deposit_amount' => 99999],
        ],
    ]);

    expect($vertical->default_services[0]['deposit_amount'])->toBe(12000);
});

it('keeps a garage tenant on its own nouns everywhere the operator app speaks', function () {
    ['tenant' => $tenant] = aGarage();
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

    actingAsTenant($owner)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('vertical.subject_plural', 'vehicles')
            ->where('vertical.business_noun', 'garage')
            ->where('vertical.subject_singular', 'vehicle'));

    expect(Tenant::query()->find($tenant->id)->vertical()['subject_plural'])->toBe('vehicles');
});

it('stores every field key already in the form the one rule produces', function () {
    foreach (Vertical::query()->get() as $vertical) {
        foreach ($vertical->subject_fields ?? [] as $field) {
            expect(Vertical::fieldKey($field['key'], $field['label']))->toBe(
                $field['key'],
                "{$vertical->key}.{$field['label']} stores a key the derivation rule would rewrite",
            );
        }
    }
});

it('gives a migration-written field and a model-written field the same key for the same label', function () {
    $garage = Vertical::query()->where('key', 'garage')->firstOrFail();

    $written = Vertical::query()->create([
        'key' => 'tyres',
        'label' => 'Tyres',
        'business_noun' => 'depot',
        'subject_singular' => 'vehicle',
        'subject_plural' => 'vehicles',
        'subject_fields' => [['label' => 'Vehicle registration', 'required' => true]],
    ]);

    $migrated = collect($garage->subject_fields)->firstWhere('label', 'Vehicle registration');

    expect($written->subject_fields[0]['key'])->toBe($migrated['key']);
});
