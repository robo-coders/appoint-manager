<?php

namespace App\Enums;

enum PreferredTime: string
{
    case Any = 'any';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
}
