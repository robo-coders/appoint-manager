<?php

namespace App\Http\Requests\Bookings;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;

class StoreManualBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Booking::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'service_id' => ['required', 'integer'],
            'staff_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'subject_id' => ['nullable', 'integer'],
            'subject_name' => ['nullable', 'string', 'max:255'],
            'subject_attributes' => ['nullable', 'array'],
            'rebook_interval_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ];

        foreach (current_tenant()?->vertical()['subject_fields'] ?? [] as $field) {
            $rules['subject_attributes.'.$field['key']] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
