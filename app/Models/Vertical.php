<?php

namespace App\Models;

use Database\Factories\VerticalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Vertical extends Model
{
    /** @use HasFactory<VerticalFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'label',
        'business_noun',
        'subject_singular',
        'subject_plural',
        'customer_singular',
        'appointment_singular',
        'subject_fields',
        'default_services',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_fields' => 'array',
            'default_services' => 'array',
        ];
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'label' => $this->label,
            'business_noun' => $this->business_noun,
            'subject_singular' => $this->subject_singular,
            'subject_plural' => $this->subject_plural,
            'customer_singular' => $this->customer_singular,
            'appointment_singular' => $this->appointment_singular,
            'subject_fields' => $this->subject_fields ?? [],
            'default_services' => $this->default_services ?? [],
        ];
    }

    public function note(): string
    {
        $definition = $this->definition();

        return count($definition['subject_fields'] ?? []) > 0
            ? Str::lower($definition['subject_plural']).' · per visit'
            : Str::lower($definition['customer_singular']).'s only';
    }

    /** @return array<string, mixed> */
    public static function definitionFor(?string $key): array
    {
        $vertical = filled($key)
            ? static::query()->where('key', $key)->first()
            : null;

        $vertical ??= static::query()->where('key', 'groomer')->first();

        return $vertical?->definition() ?? [
            'label' => 'Dog grooming',
            'business_noun' => 'salon',
            'subject_singular' => 'dog',
            'subject_plural' => 'dogs',
            'customer_singular' => 'client',
            'appointment_singular' => 'appointment',
            'subject_fields' => [],
            'default_services' => [],
        ];
    }
}
