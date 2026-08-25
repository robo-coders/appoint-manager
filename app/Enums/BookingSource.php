<?php

namespace App\Enums;

enum BookingSource: string
{
    case Online = 'online';
    case Manual = 'manual';
}
