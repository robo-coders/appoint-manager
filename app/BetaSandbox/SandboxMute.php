<?php

namespace App\BetaSandbox;

use Closure;

/**
 * Nothing leaves the building while a sandbox action is running.
 *
 * A sandbox operation invents customers and then makes the product's real
 * automation happen to them: a hold lapses, a request expires, a reminder comes
 * due. All of that is worth having actually run — that is the difference between
 * a sandbox and a screenshot — but none of it may reach Twilio or a mail server.
 * A fake client with a fake number must never be texted, and the beta tester
 * must never be emailed twelve times because they pressed "Skip 1 week".
 *
 * So the mute is a *delivery* mute, not a *logging* mute. `Notifier` still
 * writes its `messages` row for everything it would have sent, so the owner can
 * open the send log and see that the reminder went out; it simply does not hand
 * the row to `SendSms`, and does not queue the mailable. The row is marked
 * `Sent` rather than left `Queued`, because a queued row that no worker will
 * ever pick up reads as a broken product.
 *
 * **Why a process-local flag and not a config swap.** `Mail::to()->queue()` and
 * `SendSms::dispatch()` both hand work to a queue worker in another process,
 * where a swapped mail transport or a rebound `SmsGateway` would not apply. The
 * only place the decision can be made safely is before the job is created,
 * which is exactly where this is asked. It follows that every sandbox action
 * runs its automation **inline**, inside `while()`, and never defers work to a
 * queue — see `FastForward`.
 *
 * `while()` restores the previous value rather than clearing the flag, so
 * nesting is safe, and it restores in a `finally` so a thrown exception cannot
 * leave a production process permanently muted.
 */
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
