<?php

namespace App\BetaSandbox;

use Closure;

final class SandboxMute
{
    private static bool $muted = false;

    public static function isMuted(): bool
    {
        return self::$muted;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function while(Closure $callback): mixed
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            return $callback();
        } finally {
            self::$muted = $previous;
        }
    }
}
