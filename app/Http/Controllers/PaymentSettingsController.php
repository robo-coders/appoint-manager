<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentsNotConfiguredException;
use App\Services\Stripe\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentSettingsController extends Controller
{
    private function gateway(): StripeGateway
    {
        return app(StripeGateway::class);
    }

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
