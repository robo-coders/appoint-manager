<?php

namespace Database\Factories;

use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeOff>
 */
class TimeOffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->utc()->addDay()->setTime(9, 0);

        return [
            'user_id' => User::factory(),
            'tenant_id' => fn (array $attributes) => User::query()->find($attributes['user_id'])->tenant_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
            'reason' => fake()->optional()->sentence(),
            'is_all_day' => false,
        ];
    }

    public function allDay(): static
    {
        return $this->state(function (array $attributes) {
            $startsAt = now()->utc()->addDay()->startOfDay();

            return [
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addDay(),
                'is_all_day' => true,
            ];
        });
    }
}
