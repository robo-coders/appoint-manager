<?php

namespace App\Http\Requests\Onboarding;

use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\ExistsForTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step five: the booking link, and the button that ends onboarding.
 *
 * **The slug is checked again here, and that is the point of the check.**
 * Between saving step one and reaching step five a person can spend twenty
 * minutes on services and staff, and another salon can register in that window
 * and be given the same address — `TenantSlug::generate()` only guarantees the
 * slug was free at the moment it ran. Catching it here turns a unique-index
 * violation and a 500 into a named error the screen can act on: the flow sends
 * the person back to step one with the field marked, rather than to a stack
 * trace on the last click of setup.
 *
 * The slug is not editable on this screen. It is submitted as a hidden value so
 * the collision can be detected at all; correcting it happens on step one,
 * where the field and its live availability check already live.
 */
class CompleteOnboardingRequest extends FormRequest
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
            'slug' => [
                'required',
                'string',
                Rule::unique(Tenant::class, 'slug')->ignore(current_tenant_id()),
            ],

            /*
             * The optional first appointment, carried over from the step this
             * one replaced. `nullable` on the group and `required_with` on each
             * part: the page sends the whole object or null, never a half of
             * one, and validating it field by field would make "I skipped it"
             * indistinguishable from "I left the name out".
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

    public function messages(): array
    {
        return [
            'slug.unique' => 'Someone claimed that address while you were setting up. Pick another one on step one.',
        ];
    }
}
