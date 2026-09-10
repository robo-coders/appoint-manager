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

/**
 * The one form that creates a business.
 *
 * Every message here is written out rather than left to Laravel's defaults, and
 * that is the point of the file as much as the rules are. `Auth/Register.vue`
 * renders each error under the field it belongs to, so a message has to be a
 * sentence somebody can act on in that position — "The email has already been
 * taken" is a sentence about a database index, and the person reading it has an
 * account and needs the door.
 *
 * The rules are mirrored in the page's own client-side check, which runs first
 * so a typo costs no round trip. The mirroring is deliberate and the wording is
 * shared: two different sentences for the same failure, depending on whether
 * the browser or the server noticed, is a form that looks like it is guessing.
 */
class RegisterRequest extends FormRequest
{
    /**
     * Failed attempts allowed per email-and-IP before the form locks.
     *
     * Ten, where signing in allows five, because these two are counting
     * different things. A failure here is a mistyped password confirmation, an
     * address that is already registered, a trade left unchosen — an honest
     * person filling in six fields for the first time, who may well spend three
     * attempts on it. A failure on the login form is a wrong password, and five
     * of those is somebody guessing.
     *
     * `throttle:register` on the route is set well above this (see the note on
     * the limiters in `AppServiceProvider`): the middleware exists to stop a
     * flood, this exists to answer a person, and the one that answers has to be
     * the one that fires first — otherwise the friendly message is unreachable
     * and what a new salon actually sees is the 429 page.
     */
    private const MAX_ATTEMPTS = 10;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Lowercase the address, and refuse the form if it is locked.
     *
     * The `lowercase` rule below used to be the whole story, which meant
     * "Maya@Example.com" — a perfectly ordinary way to type your own address,
     * and what iOS offers as an autocapitalised suggestion — was rejected with
     * "the email must be lowercase". The rule stays as the guarantee that
     * nothing downstream ever sees a mixed-case address; this is what makes it
     * something the person filling in the form can never fail.
     */
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
