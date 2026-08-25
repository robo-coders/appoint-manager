<?php

namespace App\Services\Availability;

use ArrayIterator;
use Carbon\CarbonImmutable;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, Slot>
 * @implements Arrayable<int, array{starts_at: string, staff_ids: list<int>}>
 */
final class SlotCollection implements Arrayable, Countable, IteratorAggregate
{
    /**
     * @param  list<Slot>  $slots
     */
    public function __construct(
        private array $slots = [],
    ) {}

    /**
     * @param  list<Slot>  $slots
     */
    public static function make(array $slots = []): self
    {
        return new self(array_values($slots));
    }

    public function count(): int
    {
        return count($this->slots);
    }

    public function first(): ?Slot
    {
        return $this->slots[0] ?? null;
    }

    public function last(): ?Slot
    {
        if ($this->slots === []) {
            return null;
        }

        return $this->slots[array_key_last($this->slots)];
    }

    public function containsStart(mixed $startsAt): bool
    {
        $needle = $startsAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($startsAt)->utc()->getTimestamp()
            : CarbonImmutable::parse((string) $startsAt)->utc()->getTimestamp();

        foreach ($this->slots as $slot) {
            if ($slot->startsAt->utc()->getTimestamp() === $needle) {
                return true;
            }
        }

        return false;
    }

    public function staffIdsFor(mixed $startsAt): array
    {
        $needle = $startsAt instanceof \DateTimeInterface
            ? CarbonImmutable::instance($startsAt)->utc()->getTimestamp()
            : CarbonImmutable::parse((string) $startsAt)->utc()->getTimestamp();

        foreach ($this->slots as $slot) {
            if ($slot->startsAt->utc()->getTimestamp() === $needle) {
                return $slot->staffIds;
            }
        }

        return [];
    }

    /**
     * @return Traversable<int, Slot>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->slots);
    }

    /**
     * @return list<array{starts_at: string, staff_ids: list<int>}>
     */
    public function toArray(): array
    {
        return array_map(fn (Slot $slot) => $slot->toArray(), $this->slots);
    }
}
