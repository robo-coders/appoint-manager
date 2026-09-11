<?php

namespace App\Support;

use App\Models\Vertical;
use Illuminate\Support\Arr;
use RuntimeException;

final class VerticalFigures
{
    /** @var array<string, mixed> */
    private array $definition;

    public function __construct(private string $key)
    {
        $this->definition = Vertical::definitionFor($key);
    }

    public function label(): string
    {
        return (string) ($this->definition['label'] ?? 'Appointments');
    }

    public function subject(): string
    {
        return (string) ($this->definition['subject_singular'] ?? 'subject');
    }

    /** @return list<array{name: string, minutes: int, price: Money, deposit: Money}> */
    public function priceList(): array
    {
        return array_map(fn (array $service) => [
            'name' => (string) $service['name'],
            'minutes' => (int) $service['duration_minutes'],
            'price' => new Money((int) $service['price']),
            'deposit' => new Money((int) $service['deposit_amount']),
        ], $this->services());
    }

    /** @return array{name: string, duration_minutes: int, price: int, deposit_amount: int} */
    private function headline(): array
    {
        $services = $this->services();

        if ($services === []) {
            throw new RuntimeException(sprintf('the %s vertical has an empty price list.', $this->key));
        }

        usort($services, fn (array $a, array $b) => [$b['duration_minutes'], $b['price']] <=> [$a['duration_minutes'], $a['price']]);

        /** @var array{name: string, duration_minutes: int, price: int, deposit_amount: int} */
        return $services[0];
    }

    public function slotName(): string
    {
        return (string) $this->headline()['name'];
    }

    public function slotMinutes(): int
    {
        return (int) $this->headline()['duration_minutes'];
    }

    public function slot(): Money
    {
        return new Money((int) $this->headline()['price']);
    }

    public function deposit(): Money
    {
        return new Money((int) $this->headline()['deposit_amount']);
    }

    public function surplus(): Money
    {
        $monthly = (int) config('billing.monthly_price_pence');

        return new Money(max(0, $this->slot()->amount - $monthly));
    }

    public function oneRefillCovers(): bool
    {
        return $this->slot()->amount >= (int) config('billing.monthly_price_pence');
    }

    public function subjectFieldList(): string
    {
        $labels = array_map(
            fn (array $field) => lcfirst((string) $field['label']),
            array_values((array) ($this->definition['subject_fields'] ?? [])),
        );

        return Arr::join($labels, ', ', ' and ');
    }

    public function hasPriceList(): bool
    {
        return $this->services() !== [];
    }

    public function hasSubjectFields(): bool
    {
        return ((array) ($this->definition['subject_fields'] ?? [])) !== [];
    }

    /** @return list<array{name: string, duration_minutes: int, price: int, deposit_amount: int}> */
    private function services(): array
    {
        /** @var list<array{name: string, duration_minutes: int, price: int, deposit_amount: int}> */
        return array_values((array) ($this->definition['default_services'] ?? []));
    }
}
