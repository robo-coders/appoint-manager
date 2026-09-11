<?php

namespace App\Enums;

enum LoyaltyCardStatus: string
{
    case Active = 'active';
    case StampedOut = 'stamped_out';
    case Redeemed = 'redeemed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Collecting',
            self::StampedOut => 'Free session ready',
            self::Redeemed => 'Free session taken',
        };
    }
}
