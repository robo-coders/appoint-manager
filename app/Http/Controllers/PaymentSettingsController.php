<?php

namespace App\Http\Controllers;

use App\Services\Stripe\StripeGateway;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaymentSettingsController extends Controller
{
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
        ]);
    }

    public function connect(StripeGateway $stripe): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        if (! $tenant->stripe_account_id) {
            $tenant->stripe_account_id = $stripe->createExpressAccount($tenant);
            $tenant->save();
        }

        $url = $stripe->createAccountLink(
            $tenant->stripe_account_id,
            route('settings.payments.return'),
            route('settings.payments.refresh'),
        );

        return redirect()->away($url);
    }

    public function refresh(StripeGateway $stripe): RedirectResponse
    {
        return $this->connect($stripe);
    }

    public function returned(StripeGateway $stripe): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant && $tenant->stripe_account_id, 403);

        $account = $stripe->retrieveAccount($tenant->stripe_account_id);
        $tenant->forceFill([
            'stripe_onboarding_complete' => $account['charges_enabled'],
            'stripe_requirements' => $account['currently_due'],
        ])->save();

        return redirect()->route('settings.payments.show');
    }
}
