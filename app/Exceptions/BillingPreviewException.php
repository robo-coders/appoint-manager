<?php

namespace App\Exceptions;

use RuntimeException;

class BillingPreviewException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self('We could not price this change. Try again in a moment.');
    }
}
