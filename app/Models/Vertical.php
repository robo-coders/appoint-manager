<?php

namespace App\Models;

use Database\Factories\VerticalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vertical extends Model
{
    /** @use HasFactory<VerticalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'label',
        'subject_singular',
        'subject_plural',
        'customer_singular',
        'appointment_singular',
        'subject_fields',
        'default_services',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_fields' => 'array',
            'default_services' => 'array',
        ];
    }

    /**
     * The array `Tenant::vertical()` and the shared Inertia prop have always
     * returned. Same keys as the old config file, so a missing type still
     * degrades to groomer rather than to a page that cannot name its subject.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => $this->label,
            'subject_singular' => $this->subject_singular,
            'subject_plural' => $this->subject_plural,
            'customer_singular' => $this->customer_singular,
            'appointment_singular' => $this->appointment_singular,
            'subject_fields' => $this->subject_fields ?? [],
            'default_services' => $this->default_services ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definitionFor(?string $key): array
    {
        $vertical = filled($key)
            ? static::query()->where('key', $key)->first()
            : null;

        $vertical ??= static::query()->where('key', 'groomer')->first();

        return $vertical?->definition() ?? [
            'label' => 'Dog grooming',
            'subject_singular' => 'dog',
            'subject_plural' => 'dogs',
            'customer_singular' => 'client',
            'appointment_singular' => 'appointment',
            'subject_fields' => [],
            'default_services' => [],
        ];
    }
}
