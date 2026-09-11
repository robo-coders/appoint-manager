<?php

namespace App\Http\Requests\SuperAdmin;

use App\Http\Requests\SuperAdmin\Concerns\DefinesVertical;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVerticalRequest extends FormRequest
{
    use DefinesVertical;

    public function authorize(): bool
    {
        return $this->user()?->is_super_admin ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'key' => ['prohibited'],
            ...$this->definitionRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'key.prohibited' => 'A key cannot be changed after the vertical is created.',
            ...$this->definitionMessages(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateDefinitionShape($v));
    }

    protected function prepareForValidation(): void
    {
        $this->pruneBlankRows();
    }
}
