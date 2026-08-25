<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<Money, int>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        $currency = 'GBP';

        if (array_key_exists('currency', $attributes) && is_string($attributes['currency'])) {
            $currency = $attributes['currency'];
        } elseif (current_tenant()?->currency) {
            $currency = current_tenant()->currency;
        } elseif (method_exists($model, 'tenant') && $model->relationLoaded('tenant') && $model->tenant) {
            $currency = $model->tenant->currency;
        }

        return new Money((int) $value, $currency);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        if ($value instanceof Money) {
            return $value->amount;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        throw new InvalidArgumentException('Money must be set as an integer number of pence or a Money instance.');
    }
}
