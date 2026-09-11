<?php

namespace App\Http\Requests\SuperAdmin\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait DefinesVertical
{
    private const FIELD_TYPES = ['text', 'textarea', 'select'];

    private const INTERVAL_UNITS = ['days', 'weeks', 'months'];

    /** @return array<string, ValidationRule|array<mixed>|string> */
    protected function definitionRules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'subject_singular' => ['required', 'string', 'max:255'],
            'subject_plural' => ['required', 'string', 'max:255'],
            'customer_singular' => ['sometimes', 'required', 'string', 'max:255'],
            'appointment_singular' => ['sometimes', 'required', 'string', 'max:255'],

            'subject_fields' => ['sometimes', 'array', 'max:20'],
            'subject_fields.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'subject_fields.*.label' => ['required', 'string', 'max:255'],
            'subject_fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'subject_fields.*.required' => ['required', 'boolean'],
            'subject_fields.*.options' => ['array', 'max:30'],
            'subject_fields.*.options.*' => ['required', 'string', 'max:120'],

            'default_services' => ['sometimes', 'array', 'max:20'],
            'default_services.*.name' => ['required', 'string', 'max:255'],
            'default_services.*.duration_minutes' => ['required', 'integer', 'min:5', 'max:480', 'multiple_of:5'],
            'default_services.*.price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'default_services.*.deposit_amount' => ['required', 'integer', 'min:0'],
            'default_services.*.rebook_interval' => ['nullable', 'array'],
            'default_services.*.rebook_interval.value' => ['required_with:default_services.*.rebook_interval', 'integer', 'min:1', 'max:520'],
            'default_services.*.rebook_interval.unit' => ['required_with:default_services.*.rebook_interval', Rule::in(self::INTERVAL_UNITS)],
        ];
    }

    /** @return array<string, string> */
    protected function definitionMessages(): array
    {
        return [
            'subject_fields.*.key.regex' => 'A field key is lowercase letters, numbers and underscores only.',
            'subject_fields.*.options.*.required' => 'A choice cannot be blank. Remove the row instead.',
        ];
    }

    protected function validateDefinitionShape(Validator $validator): void
    {
        /** @var array<int, array<string, mixed>> $fields */
        $fields = (array) $this->input('subject_fields', []);
        $seen = [];

        foreach ($fields as $index => $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;

            if (is_string($key) && $key !== '') {
                if (in_array($key, $seen, true)) {
                    $validator->errors()->add("subject_fields.{$index}.key", 'Each field key must be different.');
                }

                $seen[] = $key;
            }

            if ((is_array($field) ? ($field['type'] ?? null) : null) !== 'select') {
                continue;
            }

            if (array_filter((array) ($field['options'] ?? []), fn ($option) => filled($option)) === []) {
                $validator->errors()->add("subject_fields.{$index}.options", 'A choice field needs at least one choice.');
            }
        }

        /** @var array<int, array<string, mixed>> $services */
        $services = (array) $this->input('default_services', []);

        foreach ($services as $index => $service) {
            if (! is_array($service)) {
                continue;
            }

            $price = $service['price'] ?? null;
            $deposit = $service['deposit_amount'] ?? null;

            if (is_numeric($price) && is_numeric($deposit) && (int) $deposit > (int) $price) {
                $validator->errors()->add("default_services.{$index}.deposit_amount", 'A deposit cannot be more than the price.');
            }
        }
    }

    protected function pruneBlankRows(): void
    {
        $merge = [];

        $blank = fn (array $row, array $keys) => array_filter(
            array_map(fn (string $key) => $row[$key] ?? null, $keys),
            fn ($value) => is_string($value) ? trim($value) !== '' : filled($value),
        ) === [];

        foreach (['subject_fields' => ['key', 'label'], 'default_services' => ['name']] as $collection => $identifying) {
            $rows = $this->input($collection);

            if (! is_array($rows)) {
                continue;
            }

            $merge[$collection] = array_values(array_filter(
                $rows,
                fn ($row) => is_array($row) && ! $blank($row, $identifying),
            ));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
