<?php

namespace App\Http\Requests\Services;

use App\Models\User;
use App\Rules\ExistsForTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('service')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:480', 'multiple_of:5'],
            'buffer_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120', 'multiple_of:5'],
            'price' => ['sometimes', 'required', 'integer', 'min:0'],
            'deposit_amount' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'staff_ids' => ['sometimes', 'array'],
            'staff_ids.*' => ['integer', ExistsForTenant::of(User::class)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->exists('deposit_amount') || ! $this->exists('price')) {
                return;
            }

            if ((int) $this->input('deposit_amount') > (int) $this->input('price')) {
                $validator->errors()->add('deposit_amount', 'Deposit cannot exceed price.');
            }
        });
    }
}
