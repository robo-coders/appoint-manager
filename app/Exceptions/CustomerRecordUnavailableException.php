<?php

namespace App\Exceptions;

use RuntimeException;

class CustomerRecordUnavailableException extends RuntimeException
{
    public static function forEmail(): self
    {
        return new self('We could not save that customer just now. Please try again.');
    }
}
