<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        return $request->user()->hasVerifiedEmail()
                    ? redirect()->intended(home_route())
                    : Inertia::render('Auth/VerifyEmail', [
                        'status' => session('status'),
                        /*
                         * The address itself. "The link we just emailed you" is
                         * the one sentence that cannot answer the only question
                         * this screen is ever asked, which is "emailed me where?"
                         * — and a typo in the address is the usual answer.
                         */
                        'email' => $request->user()->email,
                    ]);
    }
}
