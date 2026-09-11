<?php

namespace App\Services\Booking;

use App\Exceptions\CustomerRecordUnavailableException;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CustomerResolver
{
    private const ATTEMPTS = 2;

    public function resolve(Tenant $tenant, string $name, ?string $email, ?string $phone): Customer
    {
        $email = $email === null ? null : trim($email);

        if ($email === null || $email === '') {
            return $this->create($tenant, $name, null, $phone);
        }

        $failure = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($tenant, $name, $email, $phone): Customer {
                    return $this->lock($tenant, $email)
                        ?? $this->create($tenant, $name, $email, $phone);
                });
            } catch (UniqueConstraintViolationException|DeadlockException $exception) {
                $failure = $exception;
            } catch (QueryException $exception) {
                if (! $this->isDeadlock($exception)) {
                    throw $exception;
                }

                $failure = $exception;
            }

            $customer = $this->find($tenant, $email);

            if ($customer !== null) {
                return $customer;
            }
        }

        report($failure ?? CustomerRecordUnavailableException::forEmail());

        throw CustomerRecordUnavailableException::forEmail();
    }

    private function find(Tenant $tenant, string $email): ?Customer
    {
        return $this->scoped($tenant, $email)->first();
    }

    private function lock(Tenant $tenant, string $email): ?Customer
    {
        return $this->scoped($tenant, $email)->lockForUpdate()->first();
    }

    /** @return Builder<Customer> */
    private function scoped(Tenant $tenant, string $email): Builder
    {
        return Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email);
    }

    private function create(Tenant $tenant, string $name, ?string $email, ?string $phone): Customer
    {
        $customer = new Customer;
        $customer->forceFill([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]);
        $customer->save();

        return $customer;
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
