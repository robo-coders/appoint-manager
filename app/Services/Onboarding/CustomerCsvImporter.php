<?php

namespace App\Services\Onboarding;

use App\Models\Customer;
use App\Models\Subject;
use App\Models\Tenant;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

class CustomerCsvImporter
{
    /**
     * @return list<array{row: int, ok: bool, message: string}>
     */
    public function preview(Tenant $tenant, string $csv): array
    {
        return $this->walk($tenant, $csv, commit: false);
    }

    /**
     * @return list<array{row: int, ok: bool, message: string}>
     */
    public function import(Tenant $tenant, string $csv): array
    {
        return DB::transaction(fn () => $this->walk($tenant, $csv, commit: true));
    }

    /**
     * @return list<array{row: int, ok: bool, message: string}>
     */
    private function walk(Tenant $tenant, string $csv, bool $commit): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        $out = [];

        foreach ($lines as $index => $line) {
            if ($index === 0 && str_contains(strtolower($line), 'email')) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $cols = str_getcsv($line);
            $name = trim((string) ($cols[0] ?? ''));
            $email = trim((string) ($cols[1] ?? ''));
            $phone = trim((string) ($cols[2] ?? ''));
            $subjects = trim((string) ($cols[3] ?? ''));
            $row = $index + 1;

            if ($name === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[] = ['row' => $row, 'ok' => false, 'message' => 'Name and a valid email are required.'];

                continue;
            }

            if (! $commit) {
                $out[] = ['row' => $row, 'ok' => true, 'message' => $name.' · '.$email];

                continue;
            }

            $customer = Customer::query()->firstOrNew([
                'tenant_id' => $tenant->id,
                'email' => $email,
            ]);
            $customer->forceFill([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'phone' => $phone === '' ? null : PhoneNumber::toE164($phone, $tenant->country ?? 'GB'),
            ])->save();

            foreach (array_filter(array_map('trim', explode(';', $subjects))) as $subjectName) {
                Subject::query()->firstOrCreate([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $customer->id,
                    'name' => $subjectName,
                ]);
            }

            $out[] = ['row' => $row, 'ok' => true, 'message' => 'Imported '.$name];
        }

        return $out;
    }
}
