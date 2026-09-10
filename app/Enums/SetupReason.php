<?php

namespace App\Enums;

enum SetupReason: string
{
    case NoService = 'no_service';
    case NoStaff = 'no_staff';
    case NoStaffForService = 'no_staff_for_service';

    public function isPerService(): bool
    {
        return $this === self::NoStaffForService;
    }
}
