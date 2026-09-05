<?php

namespace App\Http\Requests\SuperAdmin;

use App\Http\Requests\SuperAdmin\Concerns\DefinesVertical;
use App\Models\Vertical;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVerticalRequest extends FormRequest
{
    use DefinesVertical;

    public function authorize(): bool
    {
        return $this->user()?->is_super_admin ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64', Rule::unique(Vertical::class, 'key'), 'regex:/^[a-z0-9_]+$/'],
            ...$this->definitionRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'Use lowercase letters, numbers and underscores only.',
            ...$this->definitionMessages(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateDefinitionShape($v));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('key'))) {
            $this->merge(['key' => strtolower($this->input('key'))]);
        }

        $this->pruneBlankRows();
    }
}
