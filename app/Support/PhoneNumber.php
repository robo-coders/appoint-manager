<?php

namespace App\Support;

use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class PhoneNumber
{
    public static function toE164(string $raw, string $region = 'GB'): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new InvalidArgumentException('Phone number is required.');
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($raw, strtoupper($region));
        } catch (NumberParseException $exception) {
            throw new InvalidArgumentException('Enter a valid phone number.');
        }

        if (! $util->isPossibleNumber($parsed)) {
            throw new InvalidArgumentException('Enter a valid phone number.');
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
