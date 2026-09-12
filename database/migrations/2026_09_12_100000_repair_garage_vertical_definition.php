<?php

use App\Models\Vertical;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** @var list<array<string, mixed>> */
    private const FIELDS = [
        ['label' => 'Vehicle registration', 'type' => 'text', 'required' => true],
        ['label' => 'Make & model', 'type' => 'text', 'required' => true],
        ['label' => 'Mileage', 'type' => 'text', 'required' => false],
    ];

    /** @var list<array<string, mixed>> */
    private const SERVICES = [
        ['name' => 'MOT', 'duration_minutes' => 45, 'price' => 5499, 'deposit_amount' => 0, 'rebook_interval' => ['value' => 12, 'unit' => 'months']],
        ['name' => 'Oil change / service', 'duration_minutes' => 60, 'price' => 6999, 'deposit_amount' => 2000, 'rebook_interval' => ['value' => 12, 'unit' => 'months']],
        ['name' => 'Tyre fitting (per tyre)', 'duration_minutes' => 30, 'price' => 2500, 'deposit_amount' => 500],
        ['name' => 'Brake check', 'duration_minutes' => 30, 'price' => 0, 'deposit_amount' => 0],
    ];

    public function up(): void
    {
        $garage = Vertical::query()->firstOrNew(['key' => 'garage']);

        $garage->fill([
            'label' => $garage->label ?: 'Garage',
            'business_noun' => 'garage',
            'subject_singular' => 'vehicle',
            'subject_plural' => 'vehicles',
            'customer_singular' => $garage->customer_singular ?: 'customer',
            'appointment_singular' => $garage->appointment_singular ?: 'booking',
            'subject_fields' => $garage->subject_fields ?: self::FIELDS,
            'default_services' => $this->services($garage->default_services ?? []),
        ])->save();
    }

    public function down(): void {}

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    private function services(array $existing): array
    {
        if ($existing === []) {
            return self::SERVICES;
        }

        $deposits = array_column(self::SERVICES, 'deposit_amount', 'name');
        $intervals = array_column(
            array_filter(self::SERVICES, fn (array $row): bool => isset($row['rebook_interval'])),
            'rebook_interval',
            'name',
        );

        return array_values(array_map(function (array $service) use ($deposits, $intervals): array {
            $name = (string) ($service['name'] ?? '');

            $service['deposit_amount'] = (int) ($service['deposit_amount'] ?? $deposits[$name] ?? 0);

            $interval = $service['rebook_interval'] ?? $intervals[$name] ?? null;

            if ($interval !== null) {
                $service['rebook_interval'] = $interval;
            }

            return $service;
        }, $existing));
    }
};
