<?php

namespace App\Support;

use App\Models\Tenant;
use App\Services\Booking\Proposal;
use App\Services\Booking\Suggestion;
use Carbon\CarbonImmutable;

/**
 * A suggestion, ready for the public booking page.
 *
 * Every string a customer reads is built here rather than in the island. Dates,
 * money and the refund cut-off all depend on the tenant's timezone, currency
 * and settings, and formatting any of the three in the browser means either
 * shipping that configuration to the client or getting it wrong. The island
 * renders strings it is given.
 */
final class ProposalPayload
{
    /**
     * @return array<string, mixed>
     */
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
            /*
             * The context line: the reason first, then what the appointment
             * actually is. The mockup leads with the service; leading with the
             * reason is the one addition, and it is the whole point of phase 4 —
             * see DECISIONS.md.
             */
            'context' => $suggestion->primary === null ? null : self::context($suggestion, $tenant),
            'timezone' => $tz,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function proposal(Proposal $proposal, Tenant $tenant): array
    {
        $tz = $tenant->timezone;
        $local = $proposal->startsAt->timezone($tz);

        return $proposal->toArray($tz) + [
            // "Tuesday 10 March" — the 34px line, without the year, which is
            // noise for an appointment inside the next six weeks.
            'day_label' => $local->format('l j F'),
            'cost_line' => self::costLine($proposal, $tenant),
            'free_until' => self::freeUntil($proposal, $tenant),
            'action_label' => 'Reserve '.$local->format('l').' at '.$local->format('H:i'),
            // The muted right-hand meta on an alternative row.
            'meta' => $local->format('H:i').' · '.explode(' ', trim($proposal->staff->name))[0],
        ];
    }

    /**
     * Six words, or as near as the salon's own settings allow.
     *
     * "£45.00 total, £15.00 deposit due today" when a deposit is taken;
     * "£45.00, pay on the day" when it is not. Never both halves of a sentence
     * that contradict each other.
     */
    private static function costLine(Proposal $proposal, Tenant $tenant): string
    {
        $price = $proposal->service->price;
        $deposit = $proposal->service->deposit_amount;

        if (! $tenant->takesDeposits() || $deposit->amount === 0) {
            return $price->formatted().', pay on the day';
        }

        return $price->formatted().' total, '.$deposit->formatted().' deposit due today';
    }

    /**
     * The refund window as a date she can act on, not a number of hours.
     *
     * "Free to cancel or move until Sunday 8 March" is a thing a person can put
     * in a diary. "Free to cancel up to 48 hours before" is arithmetic homework
     * set at the exact moment somebody is deciding whether to trust you.
     *
     * Null when the cut-off has already passed, which happens for a slot inside
     * the window — a same-week proposal at a salon with a long notice period.
     * The page then says the truth instead: the deposit is not refundable.
     */
    private static function freeUntil(Proposal $proposal, Tenant $tenant): ?string
    {
        $hours = (int) data_get($tenant->settings, 'booking.refund_window_hours', config('booking.refund_window_hours'));
        $cutoff = $proposal->startsAt->subHours($hours);

        if ($cutoff->lte(CarbonImmutable::now('UTC'))) {
            return null;
        }

        return $cutoff->timezone($tenant->timezone)->format('l j F');
    }

    /**
     * "Your usual Tuesday · full groom for Bramble · 90 min with Ana"
     */
    private static function context(Suggestion $suggestion, Tenant $tenant): string
    {
        $proposal = $suggestion->primary;
        $service = mb_strtolower($proposal->service->name);
        $subject = $proposal->subject?->name;
        $firstName = explode(' ', trim($proposal->staff->name))[0];

        return implode(' · ', array_filter([
            $proposal->reason,
            $subject === null ? $service : $service.' for '.$subject,
            $proposal->service->duration_minutes.' min with '.$firstName,
        ]));
    }
}
