<?php

namespace App\Exceptions;

use RuntimeException;

class WaitlistJoinUnavailableException extends RuntimeException
{
    public static function forService(): self
    {
        return new self('We could not add you to the waitlist just now. Please try again.');
    }
}
