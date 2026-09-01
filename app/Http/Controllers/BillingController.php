<?php

namespace App\Http\Controllers;

use App\Exceptions\PaymentsNotConfiguredException;
use App\Models\AuditLog;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\SmsAllowance;
use App\Services\Billing\UnconfiguredBillingGateway;
use App\Support\BillingPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(BillingGateway $billing, SmsAllowance $sms): Response
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        return Inertia::render('Billing/Index', [
            'billing' => [
                'plan' => $tenant->plan,
                'status' => $tenant->subscription_status,
                'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                'trial_days_remaining' => $tenant->trialDaysRemaining(),
                'on_trial' => $tenant->onTrial(),
                'read_only' => $tenant->isReadOnly(),
                'is_comped' => $tenant->is_comped,
                'next_charge' => $billing->nextInvoiceAt($tenant),
                'payment_method' => $billing->paymentMethodLabel($tenant),
                'invoices' => $billing->invoices($tenant),
                'monthly_price' => BillingPrice::formatPence(BillingPrice::forTenant($tenant)),
                'list_price' => BillingPrice::formatPence(BillingPrice::listMonthlyPence()),
                'has_price_override' => $tenant->monthly_price_override_pence !== null,
                /*
                 * Local without keys still needs to *see* the price. Taking a
                 * card does not work, and the screen must not offer a button
                 * whose only outcome is an error. Same shape as payments.
                 */
                'can_charge' => ! $billing instanceof UnconfiguredBillingGateway,
            ],
            'sms' => $sms->snapshot($tenant),
        ]);
    }

    public function checkout(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            return redirect()->away($billing->checkoutUrl($tenant, 'monthly'));
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }
    }

    public function topUp(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            return redirect()->away($billing->topUpCheckoutUrl($tenant));
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }
    }

    public function pause(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        try {
            $billing->pause($tenant);
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'target_tenant_id' => $tenant->id,
            'action' => 'billing.pause',
        ]);

        return back()->with('toast', 'Billing is paused. You can still use the diary.');
    }

    public function cancel(Request $request, BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $reason = $request->string('reason')->toString();

        $tenant->forceFill(['cancellation_reason' => $reason !== '' ? $reason : null])->save();

        try {
            $billing->cancel($tenant);
        } catch (PaymentsNotConfiguredException) {
            return $this->unreachable();
        }

        AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'target_tenant_id' => $tenant->id,
            'action' => 'billing.cancel',
            'meta' => ['reason' => $reason],
        ]);

        return back()->with('toast', 'Subscription cancelled. Clients can still book online.');
    }

    /**
     * A salon owner cannot set STRIPE_SECRET. Tell them the card cannot be
     * taken, not which env file is empty.
     */
    private function unreachable(): RedirectResponse
    {
        return back()->withErrors([
            'billing' => 'Card payments are not set up on this installation yet. Get in touch and we will sort it out.',
        ]);
    }
}
