<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\BookingMode;
use App\Support\Currencies;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tenant_id === current_tenant_id();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'country' => ['required', 'string', Rule::in(Currencies::countryCodes($this->settlementCurrency()))],
            'timezone' => ['required', 'string', 'timezone'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'booking_mode' => ['sometimes', 'required', Rule::enum(BookingMode::class)],
            'request_requires_deposit' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'country.required' => 'Choose where the business is based.',
            'country.in' => 'Choose a country that settles in the currency you picked.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $country = strtoupper(trim((string) $this->input('country')));
        $currency = $this->settlementCurrency();

        $this->merge([
            'country' => $country === '' && Currencies::supports($currency)
                ? Currencies::defaultCountry($currency)
                : $country,
        ]);
    }

    private function settlementCurrency(): string
    {
        $currency = current_tenant()?->currency;

        return Currencies::supports($currency) ? strtoupper((string) $currency) : Currencies::default();
    }
}
