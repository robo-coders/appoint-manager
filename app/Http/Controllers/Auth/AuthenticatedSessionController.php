<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(home_route());
    }

    /**
     * Destroy an authenticated session.
     *
     * The destination is the marketing homepage, which is Blade, not Inertia.
     * A plain `redirect()` from an Inertia request is followed by the Inertia
     * client, which then receives an HTML document it has no page component
     * for and paints it *inside* the authenticated shell — the tenant rail
     * stays on screen behind the marketing page and only a browser refresh
     * escapes it.
     *
     * `Inertia::location()` is the documented way out: it answers a 409 with
     * an `X-Inertia-Location` header, and the client turns that into a real
     * `window.location` visit. That is a full page load, which is also what we
     * want after signing out — no stale page props survive it.
     *
     * The same applies to the console session (`Admin\AdminSessionController`)
     * and to deleting an account (`ProfileController::destroy`), both of which
     * land on a non-Inertia page from an Inertia request.
     */
    public function destroy(Request $request): HttpResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return Inertia::location(marketing_url());
    }
}
