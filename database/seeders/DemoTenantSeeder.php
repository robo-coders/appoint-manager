<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\VerticalInterval;
use Illuminate\Database\Seeder;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Willow Street Grooming',
            'slug' => 'willow-street-grooming',
            'type' => 'groomer',
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
            'email' => 'hello@willowstreet.example',
            'phone' => '020 7946 0123',
            'address_line_1' => '12 Willow Street',
            'city' => 'London',
            'postcode' => 'E8 3AA',
            'onboarding_completed_at' => now(),
        ]);

        app(TenantContext::class)->set($tenant);

        $owner = User::query()->create([
            'name' => 'Maya Chen',
            'email' => 'maya@willowstreet.example',
            'password' => 'password',
            'role' => UserRole::Owner,
            'is_bookable' => true,
            'is_active' => true,
            'colour' => '#0F766E',
            'email_verified_at' => now(),
        ]);

        $staff = User::query()->create([
            'name' => 'Jordan Blake',
            'email' => 'jordan@willowstreet.example',
            'password' => 'password',
            'role' => UserRole::Staff,
            'is_bookable' => true,
            'is_active' => true,
            'colour' => '#C2410C',
            'email_verified_at' => now(),
        ]);

        $services = [];

        foreach (config('verticals.groomer.default_services') as $index => $service) {
            $services[] = Service::query()->create([
                'name' => $service['name'],
                'duration_minutes' => $service['duration_minutes'],
                'price' => $service['price'],
                'deposit_amount' => $service['deposit_amount'],
                'suggested_interval_days' => VerticalInterval::toDays($service['rebook_interval'] ?? null),
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }

        foreach ($services as $service) {
            $service->staff()->attach([$owner->id, $staff->id]);
        }

        foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $weekday) {
            foreach ([$owner, $staff] as $user) {
                AvailabilityRule::query()->create([
                    'user_id' => $user->id,
                    'weekday' => $weekday,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                ]);
            }
        }

        app(TenantContext::class)->clear();
    }
}
