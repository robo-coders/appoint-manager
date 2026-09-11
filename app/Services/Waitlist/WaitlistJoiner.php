<?php

namespace App\Services\Waitlist;

use App\Enums\PreferredTime;
use App\Exceptions\WaitlistJoinUnavailableException;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class WaitlistJoiner
{
    private const ATTEMPTS = 2;

    /**
     * @param  array<int, int|string>  $preferredDays
     */
    public function join(
        Tenant $tenant,
        Customer $customer,
        Service $service,
        array $preferredDays = [],
        ?string $preferredTimes = null,
        ?string $notes = null,
    ): WaitlistEntry {
        $failure = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($tenant, $customer, $service, $preferredDays, $preferredTimes, $notes): WaitlistEntry {
                    $waiting = $this->lock($tenant, $customer, $service);

                    return $waiting === null
                        ? $this->create($tenant, $customer, $service, $preferredDays, $preferredTimes, $notes)
                        : $this->renew($waiting);
                });
            } catch (UniqueConstraintViolationException|DeadlockException $exception) {
                $failure = $exception;
            } catch (QueryException $exception) {
                if (! $this->isDeadlock($exception)) {
                    throw $exception;
                }

                $failure = $exception;
            }

            $waiting = $this->find($tenant, $customer, $service);

            if ($waiting !== null) {
                return $this->renew($waiting);
            }
        }

        report($failure ?? WaitlistJoinUnavailableException::forService());

        throw WaitlistJoinUnavailableException::forService();
    }

    private function find(Tenant $tenant, Customer $customer, Service $service): ?WaitlistEntry
    {
        return $this->scoped($tenant, $customer, $service)->first();
    }

    private function lock(Tenant $tenant, Customer $customer, Service $service): ?WaitlistEntry
    {
        return $this->scoped($tenant, $customer, $service)->lockForUpdate()->first();
    }

    /** @return Builder<WaitlistEntry> */
    private function scoped(Tenant $tenant, Customer $customer, Service $service): Builder
    {
        return WaitlistEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('service_id', $service->id)
            ->where('is_active', true)
            ->orderBy('id');
    }

    /**
     * @param  array<int, int|string>  $preferredDays
     */
    private function create(
        Tenant $tenant,
        Customer $customer,
        Service $service,
        array $preferredDays,
        ?string $preferredTimes,
        ?string $notes,
    ): WaitlistEntry {
        $entry = new WaitlistEntry;
        $entry->forceFill([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'preferred_days' => $preferredDays,
            'preferred_times' => $preferredTimes ?: PreferredTime::Any->value,
            'notes' => $notes,
            'is_active' => true,
        ]);
        $entry->save();

        return $entry;
    }

    private function renew(WaitlistEntry $entry): WaitlistEntry
    {
        if ($entry->expires_at !== null && $entry->expires_at->isPast()) {
            $entry->forceFill(['expires_at' => null])->save();
        }

        return $entry;
    }

    private function isDeadlock(Throwable $exception): bool
    {
        if ((string) $exception->getCode() === '40001') {
            return true;
        }

        return $exception instanceof QueryException
            && ($exception->errorInfo[0] ?? null) === '40001';
    }
}
