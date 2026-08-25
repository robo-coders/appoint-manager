<?php

namespace App\Http\Requests\Services;

use App\Models\Service;
use App\Rules\ExistsForTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reorder', Service::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', ExistsForTenant::of(Service::class)],
        ];
    }
}
