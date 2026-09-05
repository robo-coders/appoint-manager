<?php

namespace App\Http\Controllers\BetaSandbox;

use App\BetaSandbox\BetaSandbox;
use App\BetaSandbox\FastForward;
use App\BetaSandbox\SampleData;
use App\BetaSandbox\SandboxNotReady;
use App\BetaSandbox\SandboxReset;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Beta sandbox. See BETA_SANDBOX.md.
 *
 * Three buttons, and the whole of the feature's safety story:
 *
 *   - **The tenant is never taken from the request.** `tenant()` reads
 *     `current_tenant()`, which `ResolveTenant` sets from the authenticated
 *     user's own row. There is no route parameter, no id in a form, and no code
 *     path here that could act on a salon other than the one signed in.
 *   - **A tampered request is refused, not ignored.** Because there is no
 *     tenant parameter, a `tenant_id` in the body would otherwise be silently
 *     dropped and the action would run against the *right* shop — which looks
 *     like success to whoever sent it. `rejectTampering()` makes it a 403
 *     instead, so an attempt to reach another salon fails loudly and leaves a
 *     403 in the access log rather than nothing at all.
 *   - **`is_beta` is checked server-side on every call.** `BetaSandbox::guard()`
 *     runs in the controller *and* again inside each of the three services, so
 *     a future caller that skips the controller still cannot wipe a real salon.
 *     A tenant outside the beta gets 404 — the same answer whether they guessed
 *     the URL or were never told there was one.
 *
 * The actions are synchronous rather than queued, which is a deliberate
 * departure from the brief's "queued job if it could be slow" and is recorded
 * in BETA_SANDBOX.md. Two things forced it: the sandbox must mute outbound
 * messages, and that mute cannot survive a hop onto a queue worker
 * (`SandboxMute` explains why); and a status column to poll would need a
 * migration the brief rules out. So the work is sized to finish inside a
 * request — the sample load is two dozen customers, not seventy — the button
 * shows Inertia's real `processing` state while it runs, and every one of them
 * comes back to a page that says in plain words what happened.
 */
class SandboxController extends Controller
{
    public function show(): Response
    {
        $tenant = $this->tenant();

        return Inertia::render('BetaSandbox/Index', [
            'shop' => [
                'customers' => $tenant->customers()->count(),
                'bookings' => $tenant->bookings()->count(),
            ],
            'intervals' => array_keys(FastForward::INTERVALS),
        ]);
    }

    public function sampleData(Request $request, SampleData $sample): RedirectResponse
    {
        $tenant = $this->tenant($request);

        try {
            $counts = $sample->load($tenant);
        } catch (SandboxNotReady $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        }

        return back()->with('toast', sprintf(
            'Sample shop loaded: %d customers, %d appointments, %d people on the waitlist.',
            $counts['customers'],
            $counts['bookings'],
            $counts['waitlist'],
        ));
    }

    public function fastForward(Request $request, FastForward $forward): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $interval = (string) $request->input('interval');

        abort_unless(array_key_exists($interval, FastForward::INTERVALS), 422);

        $result = $forward->run($tenant, $interval);

        return back()->with('toast', $this->fastForwardMessage($interval, $result));
    }

    public function reset(Request $request, SandboxReset $reset): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $removed = $reset->run($tenant);

        return back()->with('toast', sprintf(
            'Shop reset. %d customers and %d appointments removed. Your staff, services and hours are untouched.',
            $removed['customers'] ?? 0,
            $removed['bookings'] ?? 0,
        ));
    }

    /**
     * The one salon this request may touch.
     *
     * `$request` is optional because `show()` has nothing to tamper with — a
     * GET that carries a `tenant_id` is a link somebody pasted, not an attack,
     * and refusing it would only break the back button.
     */
    private function tenant(?Request $request = null): Tenant
    {
        $tenant = BetaSandbox::guard(current_tenant());

        if ($request !== null) {
            $this->rejectTampering($request, $tenant);
        }

        return $tenant;
    }

    /**
     * A request that names a salon must name this one.
     *
     * The field is not read for anything — the tenant comes from the session —
     * so this exists purely to turn a silent no-op into a refusal. "It ran, but
     * on your own shop" is the answer that would let somebody believe they had
     * reset a competitor's diary, or worse, let one of us believe a support
     * script had worked.
     */
    private function rejectTampering(Request $request, Tenant $tenant): void
    {
        foreach (['tenant_id', 'tenant', 'tenant_slug'] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $value = (string) $request->input($field);

            abort_unless(
                $value === (string) $tenant->id || $value === (string) $tenant->slug,
                403,
                'A sandbox action can only be run on your own shop.',
            );
        }
    }

    /**
     * @param  array{shifted: int, released: int, declined: int, offers: int, reminders: int}  $result
     */
    private function fastForwardMessage(string $interval, array $result): string
    {
        $moved = $interval === 'week' ? 'a week' : 'a day';
        $message = 'Your shop moved forward '.$moved.'.';

        $happened = [];

        if ($result['reminders'] > 0) {
            $happened[] = $result['reminders'].' '.($result['reminders'] === 1 ? 'reminder' : 'reminders').' went out';
        }

        if ($result['released'] > 0) {
            $happened[] = $result['released'].' unpaid '.($result['released'] === 1 ? 'hold' : 'holds').' released';
        }

        if ($result['declined'] > 0) {
            $happened[] = $result['declined'].' '.($result['declined'] === 1 ? 'request' : 'requests').' expired';
        }

        if ($result['offers'] > 0) {
            $happened[] = $result['offers'].' waitlist '.($result['offers'] === 1 ? 'offer' : 'offers').' ran out';
        }

        if ($happened === []) {
            return $message.' Nothing was waiting to happen.';
        }

        return $message.' '.ucfirst(implode(', ', $happened)).'.';
    }
}
