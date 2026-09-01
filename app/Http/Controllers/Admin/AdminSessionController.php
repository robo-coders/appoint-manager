<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Surface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in to the console.
 *
 * Separate from the app surface's login on purpose: a super admin must never
 * authenticate on app.{domain}, and this session cookie is scoped to the admin
 * host alone.
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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
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

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

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
}
