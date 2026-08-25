<?php

namespace Database\Factories;

use App\Enums\SlotOfferStatus;
use App\Models\SlotOffer;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SlotOffer>
 */
class SlotOfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $starts = now()->utc()->addDay()->setTime(10, 0);

        return [
            'waitlist_entry_id' => WaitlistEntry::factory(),
            'tenant_id' => fn (array $attributes) => WaitlistEntry::query()->find($attributes['waitlist_entry_id'])->tenant_id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
            'service_id' => fn (array $attributes) => WaitlistEntry::query()->find($attributes['waitlist_entry_id'])->service_id,
            'staff_id' => fn (array $attributes) => User::factory()->create([
                'tenant_id' => WaitlistEntry::query()->find($attributes['waitlist_entry_id'])->tenant_id,
            ])->id,
            'token' => (string) Str::uuid(),
            'status' => SlotOfferStatus::Sent,
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
