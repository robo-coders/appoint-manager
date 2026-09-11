<?php

namespace App\Http\Requests\Onboarding;

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\ExistsForTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'slug' => [
                'required',
                'string',
                Rule::unique(Tenant::class, 'slug')->ignore(current_tenant_id()),
            ],

            'first_booking' => ['nullable', 'array'],
            'first_booking.customer_name' => ['required_with:first_booking', 'string', 'max:255'],
            'first_booking.customer_email' => ['nullable', 'email', 'max:255'],
            'first_booking.service_id' => ['required_with:first_booking', 'integer', ExistsForTenant::of(Service::class)],
            'first_booking.staff_id' => ['required_with:first_booking', 'integer', ExistsForTenant::of(User::class)],
            'first_booking.starts_at' => ['required_with:first_booking', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'Someone claimed that address while you were setting up. Pick another one on step one.',
        ];
    }
}
