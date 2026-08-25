<?php

namespace App\Http\Requests\Services;

use App\Models\Service;
use App\Models\User;
use App\Rules\ExistsForTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Service::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480', 'multiple_of:5'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:120', 'multiple_of:5'],
            'price' => ['required', 'integer', 'min:0'],
            'deposit_amount' => ['required', 'integer', 'min:0', 'lte:price'],
            'is_active' => ['sometimes', 'boolean'],
            'staff_ids' => ['present', 'array'],
            'staff_ids.*' => ['integer', ExistsForTenant::of(User::class)],
        ];
    }
}
