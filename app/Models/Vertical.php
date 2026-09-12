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

    private const FIELD_TYPES = ['text', 'textarea', 'select'];

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

    protected static function booted(): void
    {
        static::saving(function (Vertical $vertical): void {
            $vertical->subject_fields = self::normaliseFields($vertical->subject_fields ?? []);
            $vertical->default_services = self::normaliseServices($vertical->default_services ?? []);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_fields' => 'array',
            'default_services' => 'array',
        ];
    }

    public static function fieldKey(?string $key, ?string $label): string
    {
        $derived = Str::of((string) $key)->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->value();

        if ($derived !== '') {
            return $derived;
        }

        return Str::of((string) $label)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private static function normaliseFields(array $fields): array
    {
        $normalised = [];
        $used = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            $key = self::fieldKey($field['key'] ?? null, $label);

            if ($key === '' || $label === '') {
                continue;
            }

            $candidate = $key;
            $suffix = 2;

            while (in_array($candidate, $used, true)) {
                $candidate = $key.'_'.$suffix++;
            }

            $used[] = $candidate;
            $field['key'] = $candidate;
            $field['label'] = $label;
            $field['type'] = in_array($field['type'] ?? null, self::FIELD_TYPES, true) ? $field['type'] : 'text';
            $field['required'] = (bool) ($field['required'] ?? false);
            $field['options'] = array_values((array) ($field['options'] ?? []));

            $normalised[] = $field;
        }

        return $normalised;
    }

    /**
     * @param  array<int, mixed>  $services
     * @return list<array<string, mixed>>
     */
    private static function normaliseServices(array $services): array
    {
        $normalised = [];

        foreach ($services as $service) {
            if (! is_array($service) || trim((string) ($service['name'] ?? '')) === '') {
                continue;
            }

            $service['name'] = trim((string) $service['name']);
            $service['duration_minutes'] = max(5, (int) ($service['duration_minutes'] ?? 60));
            $service['price'] = max(0, (int) ($service['price'] ?? 0));
            $service['deposit_amount'] = min(
                $service['price'],
                max(0, (int) ($service['deposit_amount'] ?? 0)),
            );

            $normalised[] = $service;
        }

        return $normalised;
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
