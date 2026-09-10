<?php

namespace App\Http\Requests\Onboarding;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step four: one other person, or nobody.
 *
 * The whole step is optional — most salons on day one are one person — so
 * `staff` is `nullable` and skipping sends nothing at all. What is not optional
 * is that a half-filled row be rejected: a name with no address cannot be
 * invited, and an address with no name produces a column in the diary headed by
 * an email.
 *
 * **A duplicate address is an error on the field, not a silent skip.** The old
 * version of this step matched against existing users and `continue`d past the
 * ones it already had, so inviting somebody twice looked exactly like inviting
 * them once and the person filling it in was told nothing. Uniqueness is
 * checked across the whole `users` table rather than within the tenant, because
 * that is what the login does — an address that belongs to another salon cannot
 * be given a second account here either way.
 */
class UpdateStaffRequest extends FormRequest
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

    /*
     * An empty row is a skip, not a validation failure. The form posts whatever
     * is in its fields, and a person who typed a name, thought better of it and
     * cleared it should get the same outcome as a person who never typed
     * anything. Both name and email have to be blank — one of the two filled in
     * is a mistake worth reporting, and `required_with` above reports it.
     */
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
