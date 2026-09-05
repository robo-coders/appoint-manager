<?php

namespace App\BetaSandbox;

use RuntimeException;

/**
 * The sandbox was asked to build a diary for a shop that has nothing to build
 * one from.
 *
 * Its message is written for the owner, not for a log: it is rendered straight
 * onto the settings screen as an inline error, so it has to name the thing they
 * can go and do. A sandbox that silently produced an empty diary would be a
 * bug report; a stack trace would be worse.
 */
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
