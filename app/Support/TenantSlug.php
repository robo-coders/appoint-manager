<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Str;

class TenantSlug
{
    public static function generate(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'business';
        }

        if (strlen($base) < 3) {
            $base = str_pad($base, 3, 'x');
        }

        $base = substr($base, 0, 50);

        $slug = $base;
        $suffix = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $ending = '-'.$suffix;
            $slug = substr($base, 0, 50 - strlen($ending)).$ending;
            $suffix++;
        }

        return $slug;
    }
}
