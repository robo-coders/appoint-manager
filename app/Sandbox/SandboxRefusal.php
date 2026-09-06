<?php

namespace App\Sandbox;

use RuntimeException;

final class SandboxRefusal extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
