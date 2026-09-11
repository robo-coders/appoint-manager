<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function __construct(private readonly bool $failClosed = true) {}

    /** @param  Builder<Model>  $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = current_tenant_id();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);

            return;
        }

        if ($this->shouldFailClosed()) {
            $builder->whereRaw('0 = 1');
        }
    }

    private function shouldFailClosed(): bool
    {
        return $this->failClosed;
    }
}
