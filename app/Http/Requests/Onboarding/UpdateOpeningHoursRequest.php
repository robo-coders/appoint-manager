<?php

namespace App\Http\Requests\Onboarding;

use App\Models\Service;
use App\Models\User;
use App\Rules\ExistsForTenant;
use App\Support\AvailabilityOverlap;
use Illuminate\Contracts\Validation\ValidationRule;
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

            /*
             * The optional first appointment. `nullable` on the group and
             * `required_with` on each part: the page sends the whole object or
             * null, never a half of one, and validating it field by field would
             * make "I skipped it" indistinguishable from "I left the name out".
             *
             * `ExistsForTenant` on both ids for the same reason every other
             * tenant-scoped id in this app carries it — `exists` would accept
             * another salon's service.
             *
             * Email is optional. A walk-in is often a name and a phone number,
             * and `customers.email` is nullable so that person can be stored
             * without inventing an address. Public booking still requires one —
             * an online booker has somewhere to send the manage link.
             */
            'first_booking' => ['nullable', 'array'],
            'first_booking.customer_name' => ['required_with:first_booking', 'string', 'max:255'],
            'first_booking.customer_email' => ['nullable', 'email', 'max:255'],
            'first_booking.service_id' => ['required_with:first_booking', 'integer', ExistsForTenant::of(Service::class)],
            'first_booking.staff_id' => ['required_with:first_booking', 'integer', ExistsForTenant::of(User::class)],
            'first_booking.starts_at' => ['required_with:first_booking', 'date'],
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
