<?php

namespace App\Exceptions;

use RuntimeException;

class BookingNotCompletableException extends RuntimeException
{
    public static function notConfirmed(): self
    {
        return new self('Only a confirmed appointment can be marked as done.');
    }

    public static function notYetStarted(): self
    {
        return new self('That appointment has not happened yet.');
    }

    public static function noShowNotConfirmed(): self
    {
        return new self('Only a confirmed appointment can be marked as a no show.');
    }

    public static function noShowNotYetStarted(): self
    {
        return new self('That appointment has not happened yet.');
    }
}
