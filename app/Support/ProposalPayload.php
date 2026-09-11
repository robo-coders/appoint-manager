<?php

namespace App\Support;

use App\Models\Tenant;
use App\Services\Booking\Proposal;
use App\Services\Booking\Suggestion;
use Carbon\CarbonImmutable;

final class ProposalPayload
{
    /** @return array<string, mixed> */
    public static function suggestion(Suggestion $suggestion, Tenant $tenant): array
    {
        $tz = $tenant->timezone;

        return [
            'primary' => $suggestion->primary === null ? null : self::proposal($suggestion->primary, $tenant),
            'alternatives' => array_map(
                fn (Proposal $proposal) => self::proposal($proposal, $tenant),
                $suggestion->alternatives,
            ),
            'returning' => $suggestion->returning,
            'customer_name' => $suggestion->customer?->name,
            'subject_name' => $suggestion->subject?->name,
            'interval_days' => $suggestion->intervalDays,
            'context' => $suggestion->primary === null ? null : self::context($suggestion, $tenant),
            'timezone' => $tz,
            'state' => $suggestion->state(),
            'setup_reason' => $suggestion->setupReason?->value,
            'setup_heading' => $suggestion->setupReason === null ? null : self::setupHeading($suggestion, $tenant),
            'setup_note' => $suggestion->setupReason === null ? null : self::setupNote($suggestion, $tenant),
        ];
    }

    private static function setupHeading(Suggestion $suggestion, Tenant $tenant): string
    {
        if ($suggestion->setupReason?->isPerService() && $suggestion->service !== null) {
            return $suggestion->service->name.' is not bookable online yet';
        }

        return $tenant->name.' is not taking online bookings yet';
    }

    private static function setupNote(Suggestion $suggestion, Tenant $tenant): string
    {
        $noun = (string) ($tenant->vertical()['business_noun'] ?? 'business');

        if ($suggestion->setupReason?->isPerService() && $suggestion->service !== null) {
            return 'Nobody at this '.$noun.' is set up to take '
                .mb_strtolower($suggestion->service->name)
                .' online yet, so there are no times to show.';
        }

        return 'This '.$noun.' has not finished setting up online booking, so there are no times to show yet.';
    }

    /** @return array<string, mixed> */
    public static function proposal(Proposal $proposal, Tenant $tenant): array
    {
        $tz = $tenant->timezone;
        $local = $proposal->startsAt->timezone($tz);

        return $proposal->toArray($tz) + [
            'day_label' => $local->format('l j F'),
            'cost_line' => self::costLine($proposal, $tenant),
            'free_until' => self::freeUntil($proposal, $tenant),
            'action_label' => $tenant->isRequestMode()
                ? 'Request this time'
                : 'Reserve '.$local->format('l').' at '.$local->format('H:i'),
            'staff_first_name' => self::firstName($proposal->staff->name),
            'meta' => $local->format('H:i').' · '.self::firstName($proposal->staff->name),
        ];
    }

    private static function firstName(string $name): string
    {
        return explode(' ', trim($name))[0];
    }

    private static function costLine(Proposal $proposal, Tenant $tenant): string
    {
        $price = $proposal->service->price;
        $deposit = $proposal->service->deposit_amount;

        if (! $tenant->takesDeposits() || $deposit->amount === 0 || ($tenant->isRequestMode() && ! $tenant->request_requires_deposit)) {
            return $price->formatted().', pay on the day';
        }

        return $price->formatted().' total, '.$deposit->formatted().' deposit due today';
    }

    private static function freeUntil(Proposal $proposal, Tenant $tenant): ?string
    {
        $hours = (int) data_get($tenant->settings, 'booking.refund_window_hours', config('booking.refund_window_hours'));
        $cutoff = $proposal->startsAt->subHours($hours);

        if ($cutoff->lte(CarbonImmutable::now('UTC'))) {
            return null;
        }

        return $cutoff->timezone($tenant->timezone)->format('l j F');
    }

    private static function context(Suggestion $suggestion, Tenant $tenant): string
    {
        $proposal = $suggestion->primary;
        $service = mb_strtolower($proposal->service->name);
        $subject = $proposal->subject?->name;
        $firstName = self::firstName($proposal->staff->name);

        return implode(' · ', array_filter([
            $proposal->reason,
            $subject === null ? $service : $service.' for '.$subject,
            $proposal->service->duration_minutes.' min with '.$firstName,
        ]));
    }
}
