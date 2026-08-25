<?php

namespace App\Http\Requests\Onboarding;

use App\Models\User;
use App\Support\AvailabilityOverlap;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Rules\ExistsForTenant;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOpeningHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array'],
            'rules.*.user_id' => ['required', 'integer', ExistsForTenant::of(User::class)],
            'rules.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rules' => collect($this->input('rules', []))->map(function ($rule) {
                foreach (['start_time', 'end_time'] as $field) {
                    if (isset($rule[$field]) && is_string($rule[$field]) && strlen($rule[$field]) >= 5) {
                        $rule[$field] = substr($rule[$field], 0, 5);
                    }
                }

                return $rule;
            })->all(),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('rules', []) as $index => $rule) {
                if (! isset($rule['start_time'], $rule['end_time'])) {
                    continue;
                }

                if ($rule['end_time'] <= $rule['start_time']) {
                    $validator->errors()->add(
                        "rules.$index.end_time",
                        'End time must be after start time.',
                    );
                }
            }

            $grouped = [];

            foreach ($this->input('rules', []) as $rule) {
                if (! isset($rule['user_id'], $rule['weekday'], $rule['start_time'], $rule['end_time'])) {
                    continue;
                }

                $key = $rule['user_id'].':'.$rule['weekday'];
                $grouped[$key][] = [
                    'start_time' => $rule['start_time'],
                    'end_time' => $rule['end_time'],
                ];
            }

            foreach ($grouped as $dayRanges) {
                if (AvailabilityOverlap::rangesOverlap($dayRanges)) {
                    $validator->errors()->add('rules', 'Time ranges cannot overlap for the same person on the same day.');

                    return;
                }
            }
        });
    }
}
