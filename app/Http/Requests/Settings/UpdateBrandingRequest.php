<?php

namespace App\Http\Requests\Settings;

use App\Support\BrandPalette;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'brand_colour' => ['nullable', 'string', Rule::in(BrandPalette::names())],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_colour.in' => 'Choose one of the six colours.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('brand_colour') === '') {
            $this->merge(['brand_colour' => null]);
        }
    }
}
