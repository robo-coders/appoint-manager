<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

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
