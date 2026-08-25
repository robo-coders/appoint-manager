<?php

namespace App\Http\Requests\Availability;

use App\Support\AvailabilityOverlap;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('staff')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ranges' => ['present', 'array'],
            'ranges.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'ranges.*.start_time' => ['required', 'date_format:H:i'],
            'ranges.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'ranges' => collect($this->input('ranges', []))->map(function ($range) {
                foreach (['start_time', 'end_time'] as $field) {
                    if (isset($range[$field]) && is_string($range[$field]) && strlen($range[$field]) >= 5) {
                        $range[$field] = substr($range[$field], 0, 5);
                    }
                }

                return $range;
            })->all(),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('ranges', []) as $index => $range) {
                if (! isset($range['start_time'], $range['end_time'])) {
                    continue;
                }

                if ($range['end_time'] <= $range['start_time']) {
                    $validator->errors()->add(
                        "ranges.$index.end_time",
                        'End time must be after start time.',
                    );
                }
            }

            $grouped = [];

            foreach ($this->input('ranges', []) as $range) {
                if (! isset($range['weekday'], $range['start_time'], $range['end_time'])) {
                    continue;
                }

                $grouped[$range['weekday']][] = [
                    'start_time' => $range['start_time'],
                    'end_time' => $range['end_time'],
                ];
            }

            foreach ($grouped as $dayRanges) {
                if (AvailabilityOverlap::rangesOverlap($dayRanges)) {
                    $validator->errors()->add('ranges', 'Time ranges cannot overlap for the same person on the same day.');

                    return;
                }
            }
        });
    }
}
