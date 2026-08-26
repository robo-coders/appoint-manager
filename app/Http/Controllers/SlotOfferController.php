<?php

namespace App\Http\Controllers;

use App\Exceptions\OfferUnavailableException;
use App\Exceptions\PaymentSetupFailedException;
use App\Models\SlotOffer;
use App\Models\Tenant;
use App\Services\Booking\AppointmentSuggester;
use App\Services\Booking\BookingService;
use App\Support\ProposalPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class SlotOfferController extends Controller
{
    /**
     * The offer, and — if it has already gone — the next appointment.
     *
     * The *taken* state is a designed state, not an error: several people were
     * texted about the same slot and only one of them can have it, which is the
     * mechanic working exactly as intended. So this always computes a fallback
     * proposal for the same customer, and the page shows it in the same
     * dominant type instead of a red box saying "sorry".
     *
     * Cost is worked out here for the same reason it is on the booking page:
     * currency and timezone live on the server, and formatting either one in
     * the browser means shipping the salon's configuration to it.
     */
    public function show(string $token, AppointmentSuggester $suggester): View
    {
        $offer = $this->offer($token);
        $tenant = $offer->tenant;
        $entry = $offer->waitlistEntry;
        $tz = $tenant->timezone;
        $starts = $offer->starts_at?->timezone($tz);
        $service = $offer->service;

        $fallback = null;

        if ($entry?->customer !== null) {
            $suggestion = $suggester->suggest($tenant, $entry->customer, $service);

            if ($suggestion->primary !== null) {
                $proposal = ProposalPayload::proposal($suggestion->primary, $tenant);
                $fallback = [
                    'day_label' => $proposal['day_label'],
                    'weekday' => $proposal['day'] ? explode(' ', $proposal['day'])[0] : '',
                    'time' => $proposal['time'],
                    'context' => $suggestion->toArray($tz)['primary']['reason'].' · '.$this->context($offer),
                    'cost_line' => $proposal['cost_line'],
                    'reason' => $proposal['reason'],
                    'meta' => $proposal['meta'],
                    'url' => book_url($tenant).'?service='.$proposal['service_id'],
                ];
            }
        }

        return view('offer', [
            'tenant' => $tenant,
            'props' => [
                'offer' => [
                    'token' => $offer->token,
                    'status' => $offer->status->value,
                    'starts_at' => $offer->starts_at?->utc()->toIso8601String(),
                    'day_label' => $starts?->format('l j F'),
                    'weekday' => $starts?->format('l'),
                    'time' => $starts?->format('H:i'),
                    'service_name' => $service?->name,
                    'expires_at' => $offer->expires_at?->toIso8601String(),
                    'claimable' => $offer->isClaimable(),
                    'context' => 'A slot has opened up · '.$this->context($offer),
                    'cost_line' => $this->costLine($offer, $tenant),
                ],
                'fallback' => $fallback,
                'needs_deposit' => $service && $tenant->takesDeposits() && $service->deposit_amount->amount > 0,
                'urls' => [
                    'claim' => route('offer.claim', $offer->token),
                    'book' => book_url($tenant),
                ],
                'stripePublishableKey' => config('services.stripe.key'),
            ],
        ]);
    }

    /** "full groom for Bramble · 90 min with Marek" */
    private function context(SlotOffer $offer): string
    {
        $service = mb_strtolower((string) $offer->service?->name);
        $subject = $offer->waitlistEntry?->subject?->name;
        $staff = explode(' ', trim((string) $offer->staff?->name))[0];
        $minutes = $offer->service?->duration_minutes;

        return implode(' · ', array_filter([
            $subject === null ? $service : $service.' for '.$subject,
            $minutes === null ? null : $minutes.' min with '.$staff,
        ]));
    }

    private function costLine(SlotOffer $offer, Tenant $tenant): string
    {
        $service = $offer->service;

        if ($service === null) {
            return '';
        }

        if (! $tenant->takesDeposits() || $service->deposit_amount->amount === 0) {
            return $service->price->formatted().', pay on the day';
        }

        return $service->price->formatted().' total, '.$service->deposit_amount->formatted().' deposit due today';
    }

    public function claim(string $token, BookingService $bookings): JsonResponse
    {
        $offer = $this->offer($token);

        try {
            $booking = $bookings->claimOffer($offer);
        } catch (PaymentSetupFailedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (OfferUnavailableException $exception) {
            $status = str_contains($exception->getMessage(), 'expired') ? 410 : 409;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json([
            'booking' => [
                'public_token' => $booking->public_token,
                'status' => $booking->status->value,
                'manage_url' => book_url(null, 'b/'.$booking->public_token),
            ],
            'payment' => $bookings->lastClientSecret() ? [
                'client_secret' => $bookings->lastClientSecret(),
                'connected_account' => $offer->tenant?->stripe_account_id,
            ] : null,
        ]);
    }

    private function offer(string $token): SlotOffer
    {
        $offer = SlotOffer::withoutGlobalScopes()
            ->with([
                'tenant',
                'service' => fn ($query) => $query->withoutGlobalScopes(),
                'staff' => fn ($query) => $query->withoutGlobalScopes(),
                'waitlistEntry' => fn ($query) => $query->withoutGlobalScopes(),
                'waitlistEntry.customer' => fn ($query) => $query->withoutGlobalScopes(),
                'waitlistEntry.subject' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('token', $token)
            ->first();

        abort_if($offer === null, 404);

        return $offer;
    }
}
