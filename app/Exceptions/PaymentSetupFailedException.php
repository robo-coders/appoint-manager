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
}
