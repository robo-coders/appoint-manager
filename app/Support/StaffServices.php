<?php

namespace App\Support;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

class StaffServices
{
    /** @return Collection<int, Service> */
    public static function active(): Collection
    {
        return Service::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return list<int> */
    public static function activeIds(): array
    {
        return self::active()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public static function linkAllActive(User $staff): void
    {
        $staff->services()->syncWithoutDetaching(self::activeIds());
    }

    /** @param  iterable<int|string>  $submitted */
    public static function syncActive(User $staff, iterable $submitted): void
    {
        $active = collect(self::activeIds());
        $wanted = collect($submitted)->map(fn ($id) => (int) $id)->unique()->intersect($active);

        $staff->services()->detach($active->diff($wanted)->values()->all());
        $staff->services()->syncWithoutDetaching($wanted->values()->all());
    }
}
