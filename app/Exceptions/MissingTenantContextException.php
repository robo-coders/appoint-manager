<?php

namespace App\Exceptions;

use RuntimeException;

class MissingTenantContextException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("Cannot create {$model} without a tenant context or an explicit tenant_id.");
    }
}
