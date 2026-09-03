<?php

namespace Database\Factories;

use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vertical>
 */
class VerticalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'v_'.fake()->unique()->numerify('######'),
            'label' => fake()->words(2, true),
            'subject_singular' => 'client',
            'subject_plural' => 'clients',
            'customer_singular' => 'client',
            'appointment_singular' => 'appointment',
            'subject_fields' => [],
            'default_services' => [],
        ];
    }
}
