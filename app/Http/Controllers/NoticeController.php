<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    /**
     * Notices a person is allowed to dismiss, by the key the banner sends.
     *
     * An allow-list rather than a free string: the key is written into the
     * session from a request body, and an unbounded one lets a client grow the
     * session cookie a kilobyte at a time.
     *
     * @var list<string>
     */
    private const DISMISSIBLE = ['email-verification'];

    /**
     * Dismiss a notice for the rest of this session.
     *
     * The session rather than a column, and that is the whole decision. Every
     * banner this backs is conditional on something still being wrong — an
     * unconfirmed email, a trial running down — so the lifetime that matches is
     * "stop repeating it on every page of this visit", not "never again". The
     * next visit re-asks while the condition holds, and stops on its own when
     * the condition clears, which is the behaviour a stored flag has to be
     * manually reset to get.
     *
     * `back()` is what makes the failure case honest: the banner is hidden by
     * shared state read from the session, not by local component state, so a
     * request that never lands leaves it on screen.
     */
    public function dismiss(Request $request, string $notice): RedirectResponse
    {
        abort_unless(in_array($notice, self::DISMISSIBLE, true), 404);

        $dismissed = $request->session()->get('dismissed_notices', []);

        $request->session()->put(
            'dismissed_notices',
            array_values(array_unique([...$dismissed, $notice])),
        );

        return back();
    }
}
