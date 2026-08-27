<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentsNotConfiguredException;
use App\Services\Stripe\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentSettingsController extends Controller
{
    /**
     * `StripeGateway` is deliberately not injected — not into the constructor
     * and not into these method signatures.
     *
     * Its binding refuses to resolve without platform Stripe credentials
     * (AUDIT C1: the alternative is a fake gateway that accepts forged webhook
     * signatures, so refusing is correct). Method injection made that refusal
     * happen while the container was building the action's arguments, which is
     * before a line of the action's own code ran. `connect`, `refresh` and
     * `returned` were therefore a 500 with a stack trace on any installation
     * without credentials — and `refresh`/`returned` are the two URLs Stripe
     * itself sends the owner back to, so the failure landed on someone who had
     * just left the product and come back.
     *
     * Same shape as `BookingService::gateway()`: resolved at the point of use,
     * inside the places that already know what to say when payments cannot be
     * reached. C1 is untouched — this asks the same binding the same question
     * and gets the same refusal, somewhere an answer is possible.
     */
    private function gateway(): StripeGateway
    {
        return app(StripeGateway::class);
    }

    /**
     * Whether the platform can reach Stripe at all.
     *
     * The connect screen offers a button that goes to Stripe. If the binding
     * will refuse, the honest screen says so instead of rendering a button
     * whose only outcome is an error — so `show` asks the question `connect`
     * would have asked, and renders the answer.
     */
    private function reachable(): bool
    {
        try {
            $this->gateway();

            return true;
        } catch (PaymentsNotConfiguredException) {
            return false;
        }
    }

    public function show(): Response
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $status = 'not_started';
        $due = $tenant->stripe_requirements ?? [];

        if ($tenant->stripe_onboarding_complete) {
            $status = 'ready';
        } elseif ($tenant->stripe_account_id) {
            $status = 'in_progress';
        }

        return Inertia::render('Settings/Payments', [
            'status' => $status,
            'currently_due' => $due,
            'account_id' => $tenant->stripe_account_id,
            'reachable' => $this->reachable(),
        ]);
    }

    public function connect(): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            $stripe = $this->gateway();

            if (! $tenant->stripe_account_id) {
                $tenant->stripe_account_id = $stripe->createExpressAccount($tenant);
                $tenant->save();
            }

            $url = $stripe->createAccountLink(
                $tenant->stripe_account_id,
                route('settings.payments.return'),
                route('settings.payments.refresh'),
            );
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        return redirect()->away($url);
    }

    public function refresh(): RedirectResponse
    {
        return $this->connect();
    }

    public function returned(): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant && $tenant->stripe_account_id, 403);

        try {
            $account = $this->gateway()->retrieveAccount($tenant->stripe_account_id);
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        $tenant->forceFill([
            'stripe_onboarding_complete' => $account['charges_enabled'],
            'stripe_requirements' => $account['currently_due'],
        ])->save();

        return redirect()->route('settings.payments.show');
    }

    /**
     * The sentence, in English, for the one person who cannot act on it.
     *
     * A salon owner cannot set `STRIPE_SECRET`; saying so would be telling them
     * about a file they have never seen. What they can do is take bookings
     * without deposits, which still works, and get in touch.
     */
    private function unreachable(): RedirectResponse
    {
        return redirect()
            ->route('settings.payments.show')
            ->withErrors([
                'stripe' => 'Stripe cannot be reached from this installation, so there is '
                    .'nothing to connect to yet. Bookings still work — they just cannot ask '
                    .'for a deposit. Get in touch and we will sort it out.',
            ]);
    }
}
