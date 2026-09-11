<?php

namespace App\Services;

use App\Exceptions\TokenGenerationException;
use App\Models\CalendarFeedToken;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalendarFeedTokenService
{
    private const MAX_ATTEMPTS = 5;

    public function generate(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $token = Str::random((int) config('calendar_sync.token_length'));

            $taken = CalendarFeedToken::query()
                ->withoutGlobalScopes()
                ->where('token', $token)
                ->exists();

            if (! $taken) {
                return $token;
            }
        }

        throw TokenGenerationException::afterAttempts(self::MAX_ATTEMPTS);
    }

    public function ensure(Tenant $tenant, string $scope, ?int $staffId = null): CalendarFeedToken
    {
        return DB::transaction(function () use ($tenant, $scope, $staffId): CalendarFeedToken {
            $this->hold($tenant);

            return $this->active($tenant, $scope, $staffId)->lockForUpdate()->first()
                ?? $this->issue($tenant, $scope, $staffId);
        });
    }

    public function regenerate(Tenant $tenant, string $scope, ?int $staffId = null): CalendarFeedToken
    {
        return DB::transaction(function () use ($tenant, $scope, $staffId): CalendarFeedToken {
            $this->hold($tenant);

            $superseded = $this->active($tenant, $scope, $staffId)->lockForUpdate()->get();

            foreach ($superseded as $token) {
                $token->forceFill(['revoked_at' => now()])->save();
            }

            return $this->issue($tenant, $scope, $staffId);
        });
    }

    private function hold(Tenant $tenant): void
    {
        Tenant::query()->withTrashed()->whereKey($tenant->getKey())->lockForUpdate()->first();
    }

    /** @return Builder<CalendarFeedToken> */
    private function active(Tenant $tenant, string $scope, ?int $staffId): Builder
    {
        return CalendarFeedToken::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('scope', $scope)
            ->when(
                $staffId === null,
                fn (Builder $query) => $query->whereNull('staff_id'),
                fn (Builder $query) => $query->where('staff_id', $staffId),
            )
            ->whereNull('revoked_at')
            ->orderBy('id');
    }

    private function issue(Tenant $tenant, string $scope, ?int $staffId): CalendarFeedToken
    {
        $token = new CalendarFeedToken;

        $token->forceFill([
            'tenant_id' => $tenant->getKey(),
            'staff_id' => $staffId,
            'scope' => $scope,
            'token' => $this->generate(),
        ])->save();

        return $token;
    }
}
