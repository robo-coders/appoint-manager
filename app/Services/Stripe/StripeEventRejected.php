<?php

namespace App\Services\Stripe;

use RuntimeException;

/**
 * A signed Stripe event that we refused to act on: it did not identify a booking,
 * or it came from an account that does not own the booking it named, or the money
 * did not match. Reported so it surfaces in error tracking, never thrown.
 */
class StripeEventRejected extends RuntimeException {}
