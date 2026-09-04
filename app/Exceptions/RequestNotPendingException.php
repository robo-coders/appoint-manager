<?php

namespace App\Exceptions;

use RuntimeException;

class RequestNotPendingException extends RuntimeException
{
    public static function forBooking(): self
    {
        return new self('That request has already been decided.');
    }
}
