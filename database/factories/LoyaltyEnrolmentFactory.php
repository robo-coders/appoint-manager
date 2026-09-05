<?php

namespace Database\Factories;

use App\Models\LoyaltyEnrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyEnrolment>
 */
class LoyaltyEnrolmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stamps_used' => 0,
            'cycles_completed' => 0,
        ];
    }
}
