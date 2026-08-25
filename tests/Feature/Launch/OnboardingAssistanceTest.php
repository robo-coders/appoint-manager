<?php

use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Onboarding\BookingCsvImporter;
use App\Services\Onboarding\CustomerCsvImporter;
use App\Support\TenantContext;

it('previews then imports customers and bookings', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->set($tenant);
    $staff = User::factory()->for($tenant)->owner()->create(['email' => 'groomer@example.com']);
    Service::factory()->for($tenant)->create(['name' => 'Full groom — small dog']);

    $csv = "name,email,phone,subjects\nAda Client,ada@example.com,07700900000,Buster\n";
    $preview = app(CustomerCsvImporter::class)->preview($tenant, $csv);
    expect($preview[0]['ok'])->toBeTrue()
        ->and(Customer::query()->count())->toBe(0);

    app(CustomerCsvImporter::class)->import($tenant, $csv);
    expect(Customer::query()->count())->toBe(1);

    $bookings = "customer_email,service_name,staff_email,starts_at,subject_name\nada@example.com,Full groom — small dog,groomer@example.com,2026-09-01 10:00,Buster\n";
    $dry = app(BookingCsvImporter::class)->preview($tenant, $bookings);
    expect($dry[0]['ok'])->toBeTrue()
        ->and(\App\Models\Booking::query()->count())->toBe(0);

    app(BookingCsvImporter::class)->import($tenant, $bookings);
    expect(\App\Models\Booking::query()->count())->toBe(1);
});

it('exports and hard-deletes a customer from admin', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->for($tenant)->owner()->create();
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id, 'email' => 'wipe@example.com']);

    actingAsTenant($owner)
        ->get(route('customers.export', $customer))
        ->assertOk()
        ->assertSee('wipe@example.com');

    actingAsTenant($owner)
        ->delete(route('customers.destroy', $customer))
        ->assertRedirect(route('customers.index'));

    expect(Customer::query()->find($customer->id))->toBeNull();
});

it('serves a preview link when the public page is not live', function () {
    $tenant = Tenant::factory()->create([
        'booking_page_live' => false,
        'preview_token' => 'preview-token-test',
        'onboarding_completed_at' => now(),
    ]);

    $this->get(route('public.booking.show', $tenant->slug))->assertNotFound();
    $this->get(route('booking.preview', 'preview-token-test'))->assertOk();
});
