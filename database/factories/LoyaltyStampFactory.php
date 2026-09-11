<?php

namespace Database\Factories;

use App\Enums\LoyaltyStampMethod;
use App\Models\LoyaltyStamp;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoyaltyStamp> */
class LoyaltyStampFactory extends Factory
{
    protected $model = LoyaltyStamp::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'method' => LoyaltyStampMethod::Automatic,
            'visit_date' => now()->toDateString(),
            'note' => null,
        ];
    }
}
