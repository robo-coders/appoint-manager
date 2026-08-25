<?php

namespace App\Exceptions;

use RuntimeException;

class TenantMismatchException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("Cannot create {$model} with a tenant_id that does not match the current tenant context.");
    }
}
