<?php

namespace App\Http\Requests\Onboarding;

use App\Models\Tenant;
use App\Models\Vertical;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBasicsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'slug' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'lowercase',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique(Tenant::class, 'slug')->ignore(current_tenant_id()),
            ],
            'type' => ['required', 'string', Rule::exists(Vertical::class, 'key')],

            'hours' => ['present', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'hours.*.open' => ['required', 'boolean'],
            'hours.*.start_time' => ['nullable', 'date_format:H:i'],
            'hours.*.end_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'That address is already taken. Try another.',
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'hours.size' => 'Every day of the week needs an answer.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        $this->merge([
            'slug' => is_string($slug) ? strtolower(trim($slug)) : $slug,
            'hours' => collect($this->input('hours', []))->map(function ($day) {
                if (is_array($day) && array_key_exists('open', $day)) {
                    $day['open'] = filter_var($day['open'], FILTER_VALIDATE_BOOLEAN);
                }

                return $day;
            })->all(),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $days = $this->input('hours', []);
            $anyOpen = false;

            foreach ($days as $index => $day) {
                if (! ($day['open'] ?? false)) {
                    continue;
                }

                $anyOpen = true;

                if (! isset($day['start_time'], $day['end_time'])) {
                    $validator->errors()->add(
                        "hours.$index.start_time",
                        'An open day needs an opening and a closing time.',
                    );

                    continue;
                }

                if ($day['end_time'] <= $day['start_time']) {
                    $validator->errors()->add(
                        "hours.$index.end_time",
                        'Closing time must be after opening time.',
                    );
                }
            }

            if (! $anyOpen && count($days) > 0) {
                $validator->errors()->add('hours', 'Open on at least one day, or nobody can book.');
            }
        });
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string}> */
    public function openDays(): array
    {
        $rules = [];

        foreach ($this->validated('hours') as $day) {
            if (! $day['open']) {
                continue;
            }

            $rules[] = [
                'weekday' => (int) $day['weekday'],
                'start_time' => $day['start_time'],
                'end_time' => $day['end_time'],
            ];
        }

        return $rules;
    }
}
