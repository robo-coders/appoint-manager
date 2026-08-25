<?php

namespace Database\Factories;

use App\Enums\PreferredTime;
use App\Models\Customer;
use App\Models\Service;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'tenant_id' => fn (array $attributes) => Customer::query()->find($attributes['customer_id'])->tenant_id,
            'service_id' => fn (array $attributes) => Service::factory()->create([
                'tenant_id' => Customer::query()->find($attributes['customer_id'])->tenant_id,
            ])->id,
            'preferred_days' => [2],
            'preferred_times' => PreferredTime::Any,
            'is_active' => true,
        ];
    }
}
