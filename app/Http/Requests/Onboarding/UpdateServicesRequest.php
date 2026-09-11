<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
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
            if ((int) $this->input('deposit_amount', 0) > (int) $this->input('price', 0)) {
                $validator->errors()->add('deposit_amount', 'The deposit cannot be more than the price.');
            }
        });
    }
}
