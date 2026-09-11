<?php

namespace App\BetaSandbox;

use RuntimeException;

final class SandboxNotReady extends RuntimeException
{
    public static function forTenant(): self
    {
        return new self(
            'There is nothing to build a diary from yet. Add at least one service and one '
            .'person who takes appointments, then load the sample data.'
        );
    }
}
