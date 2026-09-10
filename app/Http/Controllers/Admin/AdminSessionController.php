<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Surface;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in to the console.
 *
 * Separate from the app surface's login on purpose: a super admin must never
 * authenticate on app.{domain}, and this session cookie is scoped to the admin
 * host alone.
 *
 * ── The lockout is here, and it was nowhere ───────────────────────────────
 *
 * `Admin/Login.vue` draws a "Too many attempts" callout in ink — the mockup's
 * fourth error state, the hard stop that is deliberately not terracotta — and
 * nothing in this class had ever produced the message it renders. The only
 * limit on the console door was `throttle:admin-login`, which answers 429 with
 * a full-page error and never returns to this screen, so the state was drawn
 * and then unreachable.
 *
 * It is enforced here now, on the same shape as
 * `App\Http\Requests\Auth\LoginRequest`: three failures a minute per
 * email-and-IP, counted only on failures, cleared on a real sign-in. Three
 * rather than the app's five because this surface is ours and has two users.
 * The middleware is still there and is now a flood stop set well above it —
 * see the note on the limiters in `AppServiceProvider`.
 */
class AdminSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Admin/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotLockedOut($request);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Authenticating is not the same as being allowed in. A salon owner
        // with correct credentials gets the same message as a wrong password:
        // this surface does not confirm that an account exists.
        if (! $request->user()?->is_super_admin) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            /*
             * Counted as a failure, and it has to be: a salon owner's correct
             * password is the one credential an attacker is most likely to
             * already hold, so "authenticated but not staff" is the cheapest
             * way to probe this door. Not counting it would leave the only
             * unlimited path through the lockout.
             */
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $request->session()->regenerate();

        return redirect()->intended(Surface::Admin->path());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(Surface::Admin->path('login'));
    }

    /**
     * Three failures a minute, and then the message the page is built to show.
     *
     * @throws ValidationException
     */
    private function ensureIsNotLockedOut(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 3)) {
            return;
        }

        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /** Per email and IP, matching the operator side. */
    private function throttleKey(Request $request): string
    {
        return 'admin|'.Str::transliterate(Str::lower((string) $request->string('email')).'|'.$request->ip());
    }
}
