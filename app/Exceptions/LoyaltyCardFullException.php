<?php

namespace App\Exceptions;

use RuntimeException;

class LoyaltyCardFullException extends RuntimeException
{
    public static function notCollecting(): self
    {
        return new self('This card is not collecting stamps — it is full, or its scheme is no longer running.');
    }

    public static function alreadyStamped(): self
    {
        return new self('That appointment has already been stamped.');
    }
}
