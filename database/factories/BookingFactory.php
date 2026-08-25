<?php

namespace Database\Factories;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->utc()->addDay()->setTime(10, 0);

        return [
            'staff_id' => User::factory(),
            'service_id' => Service::factory(),
            'customer_id' => Customer::factory(),
            'tenant_id' => fn (array $attributes) => User::query()->find($attributes['staff_id'])->tenant_id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'status' => BookingStatus::Confirmed,
            'deposit_status' => DepositStatus::None,
            'price_at_booking' => 3500,
            'deposit_at_booking' => 0,
            'public_token' => (string) Str::uuid(),
            'source' => BookingSource::Manual,
        ];
    }
}
