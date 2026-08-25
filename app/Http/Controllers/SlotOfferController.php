<?php

namespace App\Http\Controllers;

use App\Exceptions\OfferUnavailableException;
use App\Exceptions\PaymentSetupFailedException;
use App\Models\SlotOffer;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class SlotOfferController extends Controller
{
    public function show(string $token): View
    {
        $offer = $this->offer($token);
        $tenant = $offer->tenant;
        $entry = $offer->waitlistEntry;

        return view('offer', [
            'tenant' => $tenant,
            'props' => [
                'offer' => [
                    'token' => $offer->token,
                    'status' => $offer->status->value,
                    'starts_at_local' => $offer->starts_at?->timezone($tenant->timezone)->format('l j M, H:i'),
                    'service_name' => $offer->service?->name,
                    'expires_at' => $offer->expires_at?->toIso8601String(),
                    'claimable' => $offer->isClaimable(),
                ],
                'needs_deposit' => $offer->service && $tenant->takesDeposits() && $offer->service->deposit_amount->amount > 0,
                'urls' => [
                    'claim' => route('offer.claim', $offer->token),
                ],
                'stripePublishableKey' => config('services.stripe.key'),
            ],
        ]);
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
            ->with(['tenant', 'service', 'staff', 'waitlistEntry.customer'])
            ->where('token', $token)
            ->first();

        abort_if($offer === null, 404);

        return $offer;
    }
}
