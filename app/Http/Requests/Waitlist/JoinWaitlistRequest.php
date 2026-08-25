<?php

namespace App\Http\Requests\Waitlist;

use App\Enums\PreferredTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JoinWaitlistRequest extends FormRequest
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
        return [
            'service_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'preferred_days' => ['nullable', 'array'],
            'preferred_days.*' => ['integer', 'between:1,7'],
            'preferred_times' => ['nullable', Rule::enum(PreferredTime::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
