<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureBillingAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = current_tenant();

        if ($tenant === null || ! $tenant->isBillingGated()) {
            return $next($request);
        }

        if ($request->routeIs('settings.billing*', 'billing.*', 'logout', 'impersonation.stop')) {
            return $next($request);
        }

        if ($request->user()?->is_super_admin && ! $request->session()->has('impersonator_id')) {
            return $next($request);
        }

        $variant = $tenant->billingGateVariant();

        return Inertia::render('Settings/Billing/AccessRequired', [
            'gate' => [
                'variant' => $variant,
                'title' => match ($variant) {
                    'unpaid' => 'Your subscription is on hold',
                    'cancelled_ended' => 'Your plan has ended',
                    default => 'Add a payment method to continue',
                },
                'body' => match ($variant) {
                    'unpaid' => 'Add a payment method to restore access.',
                    'cancelled_ended' => 'Resubscribe from billing to restore the diary.',
                    default => 'Add a payment method to continue.',
                },
                'action' => $variant === 'cancelled_ended' ? 'Resubscribe' : 'Go to billing',
            ],
        ])->toResponse($request);
    }
}
