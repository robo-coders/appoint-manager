<?php

namespace App\Services\Onboarding;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingCsvImporter
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
            $email = trim((string) ($cols[0] ?? ''));
            $serviceName = trim((string) ($cols[1] ?? ''));
            $staffEmail = trim((string) ($cols[2] ?? ''));
            $starts = trim((string) ($cols[3] ?? ''));
            $subjectName = trim((string) ($cols[4] ?? ''));
            $row = $index + 1;

            $customer = Customer::query()->where('tenant_id', $tenant->id)->where('email', $email)->first();
            $service = Service::query()->where('tenant_id', $tenant->id)->where('name', $serviceName)->first();
            $staff = User::query()->where('tenant_id', $tenant->id)->where('email', $staffEmail)->first();

            try {
                $startsAt = CarbonImmutable::parse($starts, $tenant->timezone)->utc();
            } catch (\Throwable) {
                $out[] = ['row' => $row, 'ok' => false, 'message' => 'Could not parse starts_at.'];

                continue;
            }

            if ($customer === null || $service === null || $staff === null) {
                $out[] = ['row' => $row, 'ok' => false, 'message' => 'Unknown customer, service, or staff.'];

                continue;
            }

            $message = $customer->name.' · '.$service->name.' · '.$startsAt->timezone($tenant->timezone)->toDateTimeString();

            if (! $commit) {
                $out[] = ['row' => $row, 'ok' => true, 'message' => $message];

                continue;
            }

            $subject = null;

            if ($subjectName !== '') {
                $subject = Subject::query()->firstOrCreate([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $customer->id,
                    'name' => $subjectName,
                ]);
            }

            $booking = new Booking;
            $booking->forceFill([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'subject_id' => $subject?->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($service->duration_minutes),
                'status' => BookingStatus::Confirmed,
                'deposit_status' => DepositStatus::None,
                'price_at_booking' => $service->price,
                'deposit_at_booking' => 0,
                'public_token' => (string) Str::uuid(),
                'source' => BookingSource::Manual,
            ])->save();

            $out[] = ['row' => $row, 'ok' => true, 'message' => 'Imported '.$message];
        }

        return $out;
    }
}
