<?php

namespace Database\Factories;

use App\Models\LoyaltyPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyPackage>
 */
class LoyaltyPackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Loyalty card',
            'sessions_required' => 5,
            'reward' => 'The next session is free',
            'is_active' => true,
        ];
    }
}
