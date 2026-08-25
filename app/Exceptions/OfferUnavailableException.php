<?php

namespace App\Exceptions;

use RuntimeException;

class OfferUnavailableException extends RuntimeException
{
    public static function taken(): self
    {
        return new self('Sorry, that slot was just taken.');
    }

    public static function expired(): self
    {
        return new self('This offer has expired.');
    }
}
