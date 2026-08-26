<?php

namespace App\Support;

use App\Models\Service;

class ServicePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Service $service): array
    {
        $service->loadMissing('staff');

        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'duration_minutes' => $service->duration_minutes,
            'buffer_minutes' => $service->buffer_minutes,
            'suggested_interval_days' => $service->suggested_interval_days,
            'price' => $service->price->toArray(),
            'deposit_amount' => $service->deposit_amount->toArray(),
            'is_active' => $service->is_active,
            'sort_order' => $service->sort_order,
            'staff_ids' => $service->staff->pluck('id')->all(),
        ];
    }
}
