<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Integer pence. Never constructed from a float.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class Money implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $amount,
        public string $currency = 'GBP',
    ) {
        if ($this->amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }
    }

    public function formatted(): string
    {
        $major = intdiv($this->amount, 100);
        $minor = $this->amount % 100;
        $body = number_format($major, 0, '.', ',').'.'.str_pad((string) $minor, 2, '0', STR_PAD_LEFT);

        return match ($this->currency) {
            'GBP' => '£'.$body,
            default => $this->currency.' '.$body,
        };
    }

    /**
     * @return array{amount: int, formatted: string, currency: string}
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'formatted' => $this->formatted(),
            'currency' => $this->currency,
        ];
    }

    /**
     * @return array{amount: int, formatted: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
