<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentsNotConfiguredException extends RuntimeException
{
    public static function missing(string $variable, string $because): self
    {
        return new self($variable.' is not set. '.$because);
    }
}
