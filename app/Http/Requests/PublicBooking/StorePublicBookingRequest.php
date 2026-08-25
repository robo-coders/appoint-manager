<?php

namespace App\Http\Requests\PublicBooking;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return current_tenant() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'service_id' => ['required', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'starts_at' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'subject_id' => ['nullable', 'integer'],
            'subject_name' => ['required_without:subject_id', 'nullable', 'string', 'max:255'],
            'subject_attributes' => ['nullable', 'array'],
        ];

        foreach (current_tenant()?->vertical()['subject_fields'] ?? [] as $field) {
            $key = 'subject_attributes.'.$field['key'];
            $rules[$key] = ($field['required'] ?? false) && ! $this->filled('subject_id')
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
