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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /*
             * One of the six, or nothing at all. `nullable` is the "use the
             * product's own ink" case and is a real choice a salon can make,
             * not an empty form.
             *
             * The allowed values come from tokens.css by way of BrandPalette,
             * so this rule cannot drift out of step with the swatches on the
             * screen or the colours in the stylesheet. Enforced here rather
             * than only in the UI because the UI is a suggestion: this endpoint
             * is reachable with any string, and the value it stores is
             * interpolated into a `style` attribute on a public page.
             */
            'brand_colour' => ['nullable', 'string', Rule::in(BrandPalette::names())],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_colour.in' => 'Choose one of the six colours.',
        ];
    }

    /**
     * An empty select posts '' rather than null. Without this the "no colour"
     * choice fails `Rule::in` instead of clearing the field.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('brand_colour') === '') {
            $this->merge(['brand_colour' => null]);
        }
    }
}
