<?php

namespace App\Support;

final class MaskedContact
{
    public const NOTICE = 'Contact hidden — ask an owner';

    private const BULLET = '•';

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

    public static function hasEmail(?string $email): bool
    {
        return $email !== null && trim($email) !== '';
    }
}
