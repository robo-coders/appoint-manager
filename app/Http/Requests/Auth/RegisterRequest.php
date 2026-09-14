<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 10;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
        }

        $this->ensureIsNotRateLimited();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', Rules\Password::defaults()],
            'password_confirmation' => ['required', 'same:password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'email.email' => "This doesn't look like a full email address.",
            'email.unique' => 'An account with this email already exists.',
            'password.required' => 'Choose a password.',
            'password.min' => 'Use at least eight characters.',
            'password_confirmation.required' => 'Type the password again.',
            'password_confirmation.same' => 'Those two passwords do not match.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        RateLimiter::hit($this->throttleKey());

        parent::failedValidation($validator);
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => $seconds > 60
                ? sprintf('Too many attempts. Try again in %d minutes.', (int) ceil($seconds / 60))
                : sprintf('Too many attempts. Try again in %d seconds.', $seconds),
        ]);
    }

    public function throttleKey(): string
    {
        return 'register|'.Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
