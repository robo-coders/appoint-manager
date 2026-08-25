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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'services' => ['present', 'array'],
            'services.*.id' => ['nullable', 'integer'],
            'services.*.name' => ['required', 'string', 'max:255'],
            'services.*.duration_minutes' => ['required', 'integer', 'min:5', 'max:480', 'multiple_of:5'],
            'services.*.price' => ['required', 'integer', 'min:0'],
            'services.*.deposit_amount' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('services', []) as $index => $service) {
                if ((int) ($service['deposit_amount'] ?? 0) > (int) ($service['price'] ?? 0)) {
                    $validator->errors()->add(
                        "services.$index.deposit_amount",
                        'Deposit cannot exceed price.',
                    );
                }
            }
        });
    }
}
