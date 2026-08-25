<?php

namespace App\Exceptions;

use RuntimeException;

class SlotUnavailableException extends RuntimeException
{
    public static function forSlot(): self
    {
        return new self('That time is no longer available.');
    }
}
