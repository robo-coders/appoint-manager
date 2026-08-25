<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'tenant_id' => fn (array $attributes) => Customer::query()->find($attributes['customer_id'])->tenant_id,
            'name' => fake()->firstName(),
            'attributes' => [
                'breed' => 'Labrador',
                'size' => 'medium',
            ],
        ];
    }
}
