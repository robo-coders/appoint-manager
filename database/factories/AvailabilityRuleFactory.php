<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\AvailabilityRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AvailabilityRule>
 */
class AvailabilityRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => fn (array $attributes) => User::query()->find($attributes['user_id'])->tenant_id,
            'weekday' => Weekday::Monday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ];
    }
}
