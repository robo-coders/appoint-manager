<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Support\TenantSlug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => TenantSlug::generate($name),
            'type' => 'groomer',
            'timezone' => 'Europe/London',
            'currency' => 'GBP',
            'email' => fake()->unique()->companyEmail(),
            'phone' => '020 7946 0000',
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'onboarding_completed_at' => now(),
            'trial_ends_at' => now()->addDays(30),
            'subscription_status' => 'trial',
            'booking_page_live' => true,
        ];
    }

    public function onboardingIncomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'onboarding_completed_at' => null,
        ]);
    }
}
