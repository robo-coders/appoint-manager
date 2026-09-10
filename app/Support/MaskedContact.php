<?php

namespace App\Support;

/**
 * What a customer's contact details look like to somebody not allowed to read
 * them.
 *
 * **Masked, not removed.** A blank column reads as "this customer has no phone
 * number", which is a different fact and a misleading one — it invites somebody
 * to go and "fix" a record that is not broken. A masked value says the number
 * exists and is being withheld, which is the truth.
 *
 * **The phone keeps its last two digits.** That is enough to confirm you are
 * looking at the right person when an owner reads a number out to you, and far
 * too little to ring anybody. The email keeps nothing: the useful half of an
 * address is the half in front of the `@`, so a partial email is either a
 * privacy leak or useless, and there is no version of it that is neither.
 */
final class MaskedContact
{
    /** The one sentence every masked surface says, so they all say the same one. */
    public const NOTICE = 'Contact hidden — ask an owner';

    private const BULLET = '•';

    /**
     * The number with everything but its last two digits blanked.
     *
     * Non-digits are dropped before counting so the mask is a fixed shape
     * rather than a transcription of the spacing somebody happened to type —
     * "07700 900123" and "+447700900123" are the same number, and a mask that
     * showed which one was on file would be leaking the format back.
     */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) <= 2) {
            return str_repeat(self::BULLET, 4);
        }

        return str_repeat(self::BULLET, min(8, strlen($digits) - 2)).substr($digits, -2);
    }

    /**
     * Whether there is an address on file, without saying what it is.
     *
     * Callers send this instead of the address; the screen turns it into the
     * notice. It is deliberately not a masked string — see the class note.
     */
    public static function hasEmail(?string $email): bool
    {
        return $email !== null && trim($email) !== '';
    }
}
