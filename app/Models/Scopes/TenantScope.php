<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function __construct(private readonly bool $failClosed = true) {}

    /**
     * @param  Builder<Model>  $builder
     */
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

    /**
     * Console and queue processes used to be exempt from this, which meant every
     * job and command read across all tenants whenever it forgot to pass a
     * tenant_id — silently, with no error. There is no exemption now: code that
     * legitimately spans tenants says so with withoutGlobalScopes() and an
     * explicit tenant_id, which is what all of it already does.
     */
    private function shouldFailClosed(): bool
    {
        return $this->failClosed;
    }
}
