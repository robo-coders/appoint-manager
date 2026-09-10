<?php

namespace App\Exceptions;

use RuntimeException;

class TokenGenerationException extends RuntimeException
{
    public static function afterAttempts(int $attempts): self
    {
        return new self("Could not mint a unique calendar feed token after {$attempts} attempts.");
    }
}
