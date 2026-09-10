<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step three: one service, not a price list.
 *
 * The step used to be a repeater seeded with every default service the vertical
 * knows about — six rows of somebody else's prices to read, correct or delete
 * before you could get past it. A salon needs exactly one bookable thing to
 * open, and the rest are quicker to add later against a real diary than they
 * are to audit in a form. So: one service, prefilled from the vertical's first
 * default as a starting point rather than a list to approve.
 *
 * Money is integer pence throughout, which is what `Service` casts. The browser
 * shows pounds; `lib/money.ts` does the conversion on the way in.
 */
class UpdateServicesRequest extends FormRequest
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
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480', 'multiple_of:5'],
            'price' => ['required', 'integer', 'min:0'],
            'deposit_amount' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'duration_minutes.multiple_of' => 'Use a length in five minute steps.',
            'duration_minutes.min' => 'Five minutes is the shortest appointment.',
            'price.min' => 'A price cannot be negative.',
            'deposit_amount.min' => 'A deposit cannot be negative. Use 0 for no deposit.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            /*
             * A deposit over the price would bill somebody more to hold the
             * slot than the appointment costs, and the balance on the day would
             * be negative. Zero is allowed and is a real answer — plenty of
             * trades do not take one.
             */
            if ((int) $this->input('deposit_amount', 0) > (int) $this->input('price', 0)) {
                $validator->errors()->add('deposit_amount', 'The deposit cannot be more than the price.');
            }
        });
    }
}
