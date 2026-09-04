<?php

namespace App\Enums;

enum BookingMode: string
{
    case Automated = 'automated';
    case Request = 'request';
}
