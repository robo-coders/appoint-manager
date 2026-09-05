<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An appointment that cannot be marked as having happened.
 *
 * Its own class rather than a second factory on `RequestNotPendingException`,
 * whose message — "that request has already been decided" — is about a booking
 * request being approved or declined and would be a lie on this path. Two
 * reasons, two messages, because the message is what the owner reads.
 *
 * "Completable" covers marking a no-show too: both are the owner closing out an
 * appointment that has been and gone, both refuse on exactly the same grounds,
 * and both are caught in the same place. The messages are separate for the same
 * reason the two below are — the owner reads the message, not the class.
 */
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
