<?php

namespace App\Services\Billing;

use App\Models\BillingReceipt;
use App\Models\PaymentFailure;
use App\Models\Tenant;
use App\Support\BillingPrice;
use App\Support\Money;
use Carbon\CarbonInterface;

class BillingPageData
{
    /** @return array<string, mixed> */
    public function for(Tenant $tenant, bool $canCharge): array
    {
        $state = $this->state($tenant);
        $yearly = $tenant->plan === 'yearly';
        $pricePence = $yearly ? BillingPrice::listYearlyPence() : BillingPrice::forTenant($tenant);
        $saving = (BillingPrice::listMonthlyPence() * 12) - BillingPrice::listYearlyPence();

        return [
            'state' => $state,
            'plan' => [
                'name' => (string) config('billing.plan_name'),
                'interval' => in_array($tenant->plan, ['monthly', 'yearly'], true) ? $tenant->plan : null,
                'price' => (new Money($pricePence))->formatted(),
                'period' => $yearly ? '/ year' : '/ month',
                'limits' => $this->limits(),
                'renews_on' => $this->displayDate($tenant->current_period_end),
                'yearly_saving' => $yearly && $saving > 0
                    ? 'Saving '.BillingPrice::formatPence($saving).' / yr'
                    : null,
            ],
            'payment_method' => [
                'present' => $tenant->hasCardOnFile(),
                'brand' => $tenant->card_brand,
                'last4' => $tenant->card_last4,
                'exp' => $tenant->card_last4 && $tenant->card_exp_month && $tenant->card_exp_year
                    ? sprintf('%02d/%s', $tenant->card_exp_month, substr((string) $tenant->card_exp_year, -2))
                    : null,
            ],
            'banner' => $this->banner($tenant, $state),
            'ends_on' => $this->displayDate($tenant->subscription_ends_at),
            'trial_days' => $tenant->trialDaysRemaining(),
            'invoices' => $this->invoices($tenant),
            'csv_url' => route('settings.billing.export'),
            'can_charge' => $canCharge,
            'stripe_key' => $canCharge ? (string) config('services.stripe.key') : null,
        ];
    }

    public function limits(): string
    {
        $seats = (int) config('billing.staff_seats');
        $texts = (int) config('billing.sms_included');

        return $seats.' staff seats · '.$texts.' auto-fill texts a month';
    }

    public function state(Tenant $tenant): string
    {
        if (in_array($tenant->subscription_status, ['unpaid', 'incomplete_expired'], true)) {
            return 'unpaid';
        }

        if ($tenant->cancel_at_period_end && $tenant->subscription_ends_at?->isPast()) {
            return 'cancelled_ended';
        }

        if ($tenant->cancel_at_period_end && $tenant->subscription_ends_at?->isFuture()) {
            return 'cancelled_pending';
        }

        if ($tenant->subscription_status === 'cancelled' && $tenant->subscription_ends_at?->isPast()) {
            return 'cancelled_ended';
        }

        if ($tenant->subscription_status === 'cancelled' && $tenant->subscription_ends_at?->isFuture()) {
            return 'cancelled_pending';
        }

        if ($tenant->subscription_status === 'past_due') {
            return 'past_due';
        }

        if ($tenant->subscription_status === 'incomplete') {
            return 'incomplete';
        }

        if ($tenant->subscription_status === 'trial' || ($tenant->onTrial() && ! $tenant->hasCardOnFile())) {
            return 'trial';
        }

        if ($tenant->trial_ends_at?->isPast() && ! in_array($tenant->subscription_status, ['active', 'paused', 'past_due', 'incomplete'], true)) {
            return 'trial_ended';
        }

        if (in_array($tenant->subscription_status, ['active', 'paused'], true) && ! $tenant->hasCardOnFile()) {
            return 'no_payment_method';
        }

        return 'healthy';
    }

    /** @return array{variant: string, message: string, action_label: string|null, reassurance: bool}|null */
    private function banner(Tenant $tenant, string $state): ?array
    {
        return match ($state) {
            'past_due' => $this->pastDueBanner($tenant),
            'unpaid' => [
                'variant' => 'unpaid',
                'message' => 'Your subscription is on hold. Add a payment method to restore access.',
                'action_label' => 'Add a payment method',
                'reassurance' => false,
            ],
            'trial' => [
                'variant' => 'trial',
                'message' => 'Your trial ends in '.$tenant->trialDaysRemaining().' '
                    .($tenant->trialDaysRemaining() === 1 ? 'day' : 'days')
                    .'. Add a payment method before then.',
                'action_label' => 'Add a payment method',
                'reassurance' => false,
            ],
            'incomplete' => [
                'variant' => 'incomplete',
                'message' => 'Your payment is being confirmed — this can take a minute',
                'action_label' => null,
                'reassurance' => false,
            ],
            'trial_ended' => [
                'variant' => 'trial_ended',
                'message' => 'Your trial has ended. Add a payment method to continue.',
                'action_label' => 'Add a payment method',
                'reassurance' => false,
            ],
            'cancelled_pending' => [
                'variant' => 'cancelled_pending',
                'message' => 'Your plan ends on '.$this->displayDate($tenant->subscription_ends_at)
                    .' — you\'ll keep full access until then.',
                'action_label' => null,
                'reassurance' => false,
            ],
            'cancelled_ended' => [
                'variant' => 'cancelled_ended',
                'message' => 'Your plan has ended. Resubscribe to restore access.',
                'action_label' => 'Resubscribe',
                'reassurance' => false,
            ],
            default => null,
        };
    }

    /** @return array{variant: string, message: string, action_label: string|null, reassurance: bool} */
    private function pastDueBanner(Tenant $tenant): array
    {
        $failure = PaymentFailure::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('resolved_at')
            ->orderByDesc('declined_at')
            ->first();

        $declined = $this->displayDate($failure?->declined_at) ?? 'recently';
        $retry = $this->displayDate($failure?->retry_at);
        $reason = $failure?->failure_reason ?? 'your bank declined the payment';
        $reassurance = $tenant->subscription_status === 'past_due';

        $message = 'Your card was declined on '.$declined.' — '.$reason.'.';

        if ($retry) {
            $message .= ' We will retry on '.$retry;
            $message .= $reassurance ? '; bookings and SMS auto-fill keep running until then.' : '.';
        } elseif ($reassurance) {
            $message .= ' Bookings and SMS auto-fill keep running while we retry.';
        }

        return [
            'variant' => 'past_due',
            'message' => $message,
            'action_label' => 'Update payment method',
            'reassurance' => $reassurance,
        ];
    }

    /** @return list<array{id: int, invoice_number: string, date: string, amount: string, status: string, download_url: string, declined: bool}> */
    private function invoices(Tenant $tenant): array
    {
        return BillingReceipt::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('issued_at')
            ->get()
            ->map(function (BillingReceipt $receipt) {
                $declined = $receipt->isDeclined();

                return [
                    'id' => $receipt->id,
                    'invoice_number' => $receipt->invoice_number,
                    'date' => $this->displayDate($receipt->issued_at) ?? '',
                    'amount' => (new Money($receipt->amount, strtoupper($receipt->currency)))->formatted(),
                    'status' => $declined ? 'Payment declined' : ($receipt->amount === 0 ? 'Free trial' : 'Paid'),
                    'download_url' => route('settings.billing.receipts.download', $receipt),
                    'declined' => $declined,
                ];
            })
            ->all();
    }

    private function displayDate(?CarbonInterface $date): ?string
    {
        return $date?->timezone(current_tenant()?->timezone ?? config('app.timezone'))->format('j M Y');
    }
}
