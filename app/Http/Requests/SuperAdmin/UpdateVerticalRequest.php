<?php

namespace App\Http\Requests\SuperAdmin;

use App\Http\Requests\SuperAdmin\Concerns\DefinesVertical;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Editing a vertical.
 *
 * **`key` is not in the rules and is not writable.** Tenants store it as
 * `tenants.type`, and `Vertical::definitionFor()` looks a tenant's definition up
 * by that string — so renaming a key would silently drop every tenant that had
 * chosen it back to the groomer fallback, with the wrong words for their trade
 * on their own booking page. The create form says the key cannot be changed
 * later; this is what makes that true rather than advisory.
 */
class UpdateVerticalRequest extends FormRequest
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
            // Sent by the form as a read-only field; rejected rather than
            // ignored, so a request that tries to change it fails loudly.
            'key' => ['prohibited'],
            ...$this->definitionRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
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
