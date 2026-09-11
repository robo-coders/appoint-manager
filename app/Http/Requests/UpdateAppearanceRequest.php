<?php

namespace App\Http\Requests;

use App\Enums\ThemePreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppearanceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'preference' => ['required', Rule::enum(ThemePreference::class)],
        ];
    }
}
