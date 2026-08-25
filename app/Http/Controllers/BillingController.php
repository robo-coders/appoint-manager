<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Billing\BillingGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(BillingGateway $billing): Response
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
                'monthly_price' => '£39',
                'yearly_price' => '£390',
            ],
        ]);
    }

    public function checkout(Request $request, BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $interval = $request->string('interval')->toString() === 'yearly' ? 'yearly' : 'monthly';

        return redirect()->away($billing->checkoutUrl($tenant, $interval));
    }

    public function pause(BillingGateway $billing): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $billing->pause($tenant);

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
        $billing->cancel($tenant);

        AuditLog::query()->create([
            'actor_id' => auth()->id(),
            'target_tenant_id' => $tenant->id,
            'action' => 'billing.cancel',
            'meta' => ['reason' => $reason],
        ]);

        return back()->with('toast', 'Subscription cancelled. Clients can still book online.');
    }
}
