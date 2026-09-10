<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use Carbon\Carbon;

class SubscriptionState
{
    /**
     * @param  array<string, mixed>  $subscription
     */
    public function apply(Tenant $tenant, array $subscription, ?string $plan = null): void
    {
        $status = (string) ($subscription['status'] ?? $tenant->subscription_status);
        $mapped = match ($status) {
            'trialing' => 'trial',
            'incomplete' => 'incomplete',
            'incomplete_expired' => 'incomplete_expired',
            'past_due' => 'past_due',
            'unpaid' => 'unpaid',
            'canceled', 'cancelled' => 'cancelled',
            'paused' => 'paused',
            'active' => 'active',
            default => $tenant->subscription_status,
        };

        $interval = $plan ?? $tenant->plan;
        $itemInterval = $subscription['items']['data'][0]['plan']['interval']
            ?? $subscription['items']['data'][0]['price']['recurring']['interval']
            ?? null;

        if ($itemInterval === 'year') {
            $interval = 'yearly';
        } elseif ($itemInterval === 'month') {
            $interval = 'monthly';
        }

        $periodEnd = $this->periodEnd($subscription);
        $cancelAtPeriodEnd = $mapped !== 'cancelled' && ! empty($subscription['cancel_at_period_end']);
        $endsAt = $cancelAtPeriodEnd
            ? ($periodEnd ?? $tenant->subscription_ends_at)
            : ($mapped === 'cancelled' ? ($tenant->subscription_ends_at ?? $periodEnd) : null);

        $values = [
            'stripe_subscription_id' => $subscription['id'] ?? $tenant->stripe_subscription_id,
            'subscription_status' => $mapped,
            'plan' => $interval,
            'current_period_end' => $periodEnd,
            'subscription_ends_at' => $endsAt,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'dunning_started_at' => $mapped === 'past_due'
                ? ($tenant->dunning_started_at ?? now())
                : (in_array($mapped, ['active', 'trial'], true) ? null : $tenant->dunning_started_at),
        ];

        if ($mapped === 'trial' && isset($subscription['trial_end'])) {
            $values['trial_ends_at'] = Carbon::createFromTimestamp((int) $subscription['trial_end']);
        }

        if ($mapped === 'cancelled') {
            $values['cancelled_at'] = $tenant->cancelled_at ?? now();
        }

        if ($mapped === 'active' && ! $cancelAtPeriodEnd) {
            $values['cancelled_at'] = null;
        }

        $tenant->forceFill($values)->save();
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    public function periodEnd(array $subscription): ?Carbon
    {
        $end = $subscription['current_period_end']
            ?? data_get($subscription, 'items.data.0.current_period_end');

        return $end ? Carbon::createFromTimestamp((int) $end) : null;
    }
}
