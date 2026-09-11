<?php

namespace App\Enums;

enum DepositStatus: string
{
    case None = 'none';
    case Required = 'required';
    case Paid = 'paid';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No deposit',
            self::Required => 'Deposit due',
            self::Paid => 'Deposit paid',
            self::RefundPending => 'Refund pending',
            self::Refunded => 'Refunded',
        };
    }
}
