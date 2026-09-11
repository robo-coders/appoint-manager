<?php

namespace App\Http\Requests\Onboarding;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'staff' => ['nullable', 'array'],
            'staff.name' => ['required_with:staff', 'string', 'max:255'],
            'staff.email' => [
                'required_with:staff',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:'.User::class.',email',
            ],
            'staff.can_see_customer_contacts' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'staff.name.required_with' => 'Give this person a name, or leave both fields empty to skip.',
            'staff.email.required_with' => 'An invite needs somewhere to go.',
            'staff.email.email' => 'That does not look like an email address.',
            'staff.email.unique' => 'Somebody is already using that address.',
            'staff.email.lowercase' => 'Email addresses are stored in lowercase.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $staff = $this->input('staff');

        if (! is_array($staff)) {
            return;
        }

        $name = trim((string) ($staff['name'] ?? ''));
        $email = strtolower(trim((string) ($staff['email'] ?? '')));

        $this->merge([
            'staff' => $name === '' && $email === ''
                ? null
                : ['name' => $name, 'email' => $email] + $staff,
        ]);
    }
}
