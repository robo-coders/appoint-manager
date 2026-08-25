<?php

namespace App\Enums;

enum SlotOfferStatus: string
{
    case Sent = 'sent';
    case Claimed = 'claimed';
    case Expired = 'expired';
    case Superseded = 'superseded';
}
