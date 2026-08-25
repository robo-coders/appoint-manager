<?php

namespace App\Http\Requests\TimeOff;

use App\Models\TimeOff;
use App\Models\User;
use App\Rules\ExistsForTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TimeOff::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', ExistsForTenant::of(User::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'start_time' => ['required_unless:is_all_day,true', 'nullable', 'date_format:H:i'],
            'end_time' => ['required_unless:is_all_day,true', 'nullable', 'date_format:H:i'],
            'is_all_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
