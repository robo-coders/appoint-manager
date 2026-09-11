<?php

namespace App\Enums;

enum LoyaltyStampMethod: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Automatic => 'Stamped on completion',
            self::Manual => 'Added by hand',
        };
    }
}
