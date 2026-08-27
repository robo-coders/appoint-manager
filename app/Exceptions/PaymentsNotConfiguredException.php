<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The platform has no usable Stripe gateway.
 *
 * A `RuntimeException` subclass on purpose: AUDIT C1 says the container refuses
 * to hand out a gateway rather than falling back to the fake one, and that
 * refusal is unchanged — this only gives it a name.
 *
 * The name is what the booking flow needs. "There is no gateway" and "Stripe
 * answered with an error" are different facts and the customer is owed
 * different sentences for them, but until now both arrived as an untyped
 * `RuntimeException` — one of them thrown by the container at the moment
 * `BookingService` was constructed, which is before any code that could have
 * said either sentence. See `BookingService::gateway()`.
 */
class PaymentsNotConfiguredException extends RuntimeException
{
    public static function missing(string $variable, string $because): self
    {
        return new self($variable.' is not set. '.$because);
    }
}
