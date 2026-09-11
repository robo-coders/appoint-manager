<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The loyalty setting, and the one package v1 lets a salon define.
 *
 * `enabled` is always validated; the package fields are only required when it is
 * on. A salon switching the feature *off* is not asked to fill in a form about a
 * thing it has just turned off — that is the shape of the rule, and it is why
 * these are `required_if` rather than `required`.
 *
 * The bounds are opinions, and both are deliberate:
 *
 *   - **2 to 50 sessions.** One session means every appointment is free, which
 *     is not a loyalty scheme; fifty is already further than any paper card
 *     goes, and a number above it is a typo rather than a policy.
 *   - **The reward is prose, not a type.** v1 does one thing — the next session
 *     is free — so the field describes it rather than choosing it, and the
 *     description is what appears on the customer screen. When there is a second
 *     kind of reward this becomes an enum plus this string, not instead of it.
 */
class UpdateLoyaltyRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
            'name' => ['required_if:enabled,true', 'nullable', 'string', 'max:60'],
            'sessions_required' => [
                'required_if:enabled,true',
                'nullable',
                'integer',
                'min:'.config('loyalty.min_visits_required'),
                'max:'.config('loyalty.max_visits_required'),
            ],
            'reward' => [
                'required_if:enabled,true',
                'nullable',
                'string',
                'max:'.config('loyalty.max_reward_description_length'),
            ],
            'eligible_service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where('tenant_id', current_tenant_id()),
            ],
            'auto_stamp' => ['sometimes', 'boolean'],
            'auto_enrol' => ['sometimes', 'boolean'],
            'auto_apply_reward' => ['sometimes', 'boolean'],
            'show_visit_date' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sessions_required.min' => 'Two or more. One session means every appointment is free.',
            'name.required_if' => 'Give the package a name — customers never see it, but you will.',
            'reward.required_if' => 'Say what they get, in a few words.',
            'eligible_service_id.exists' => 'Choose one of your own services, or leave it on all of them.',
        ];
    }
}
