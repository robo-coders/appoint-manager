<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Models\Vertical;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:255'],
            'business_type' => ['required', 'string', Rule::exists(Vertical::class, 'key')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            /*
             * `same:password` on the confirmation rather than `confirmed` on
             * the password, and the difference is where the message lands.
             * `confirmed` reports the mismatch on `password` — so the field the
             * person is looking at when they realise, the one they typed
             * second, says nothing, and the field above it says something about
             * a field below it. The check is identical either way.
             */
            'password' => ['required', Rules\Password::defaults()],
            'password_confirmation' => ['required', 'same:password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'business_name.required' => 'Enter the name clients will see.',
            'business_name.min' => 'That is too short to be a business name.',
            'business_type.required' => 'Choose the kind of business this is.',
            'business_type.exists' => 'Choose the kind of business this is.',
            'name.required' => 'Enter your name.',
            'email.required' => 'Enter your email address.',
            'email.email' => "This doesn't look like a full email address.",
            /*
             * The one message with a link in it. The page matches on this
             * sentence to decide which field slot renders the link — see
             * `Auth/Register.vue` — so the words "already exists" are load
             * bearing and a rewording changes two files.
             */
            'email.unique' => 'An account with this email already exists.',
            'password.required' => 'Choose a password.',
            /*
             * `Rules\Password::defaults()` reports its length failure on the
             * `password.min` key, which is the same key the page's own check
             * uses for the same sentence. The browser catches this first, so
             * this is the message an API client or a page with JavaScript off
             * gets — and it should not be the one that reads differently.
             */
            'password.min' => 'Use at least eight characters.',
            'password_confirmation.required' => 'Type the password again.',
            'password_confirmation.same' => 'Those two passwords do not match.',
        ];
    }

    /**
     * Count this failure against the lockout.
     *
     * Only failures are counted, and the controller clears the key the moment
     * an account exists, so somebody who gets it right on the fourth go starts
     * their next visit with a clean budget.
     */
    protected function failedValidation(Validator $validator): void
    {
        RateLimiter::hit($this->throttleKey());

        parent::failedValidation($validator);
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        /*
         * Reported on `email` because that is the key the page watches for a
         * lockout, and because there is no field this is about — it is about
         * the form. `Auth/Register.vue` lifts it out of the field and into a
         * banner above the whole form for that reason.
         *
         * Its own sentence rather than `trans('auth.throttle')`, whose text is
         * "Too many login attempts", which is not what happened.
         */
        throw ValidationException::withMessages([
            'email' => $seconds > 60
                ? sprintf('Too many attempts. Try again in %d minutes.', (int) ceil($seconds / 60))
                : sprintf('Too many attempts. Try again in %d seconds.', $seconds),
        ]);
    }

    /** Per address and per IP, the same shape `LoginRequest` uses. */
    public function throttleKey(): string
    {
        return 'register|'.Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
