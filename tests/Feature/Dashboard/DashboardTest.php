<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;

it('shows waitlist fills on the dashboard', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-19 10:00:00', 'Europe/London'));

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/London']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $staff = User::factory()->create(['tenant_id' => $tenant->id, 'is_bookable' => true]);
    $service = Service::factory()->create(['tenant_id' => $tenant->id, 'price' => 4000]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
    $entry = WaitlistEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
    ]);

    Booking::factory()->create([
        'tenant_id' => $tenant->id,
        'staff_id' => $staff->id,
        'service_id' => $service->id,
        'customer_id' => $customer->id,
        'waitlist_entry_id' => $entry->id,
        'starts_at' => CarbonImmutable::parse('2026-08-19 11:00:00', 'Europe/London')->utc(),
        'ends_at' => CarbonImmutable::parse('2026-08-19 12:00:00', 'Europe/London')->utc(),
        'status' => BookingStatus::Confirmed,
        'price_at_booking' => 4000,
    ]);

    actingAsTenant($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('stats.1.value', '1')
            ->where('stats.1.key', 'waitlist'));
});
