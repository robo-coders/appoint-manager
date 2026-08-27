<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * We could not set up the card payment for a booking, so the hold was released.
 * The customer has not been charged anything.
 */
class PaymentSetupFailedException extends RuntimeException
{
    public static function forBooking(?Throwable $previous = null): self
    {
        return new self(
            'We could not reach payments, so nothing has been charged and the slot has been released. Please try again in a moment.',
            0,
            $previous,
        );
    }

    /**
     * The same outcome, told honestly.
     *
     * "Try again in a moment" is the right sentence for a Stripe outage and the
     * wrong one for a platform with no Stripe credentials at all: the second
     * customer would retry all afternoon. Both release the slot and charge
     * nothing; only one of them is worth waiting for.
     */
    public static function notConfigured(?Throwable $previous = null): self
    {
        return new self(
            'Card payments are not available on this booking page at the moment, so nothing has been '
            .'charged and the slot has been released. Please call the salon to book.',
            0,
            $previous,
        );
    }
}
