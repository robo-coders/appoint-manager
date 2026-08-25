<?php

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContextException;
use App\Exceptions\TenantMismatchException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 *
 * @property int|null $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope(static::tenantScopeFailClosed()));

        static::creating(function (Model $model): void {
            if ($model instanceof \App\Models\User && $model->is_super_admin) {
                return;
            }

            $contextId = current_tenant_id();
            $explicit = $model->getAttribute('tenant_id');

            if ($explicit !== null && $contextId !== null && (int) $explicit !== (int) $contextId) {
                throw TenantMismatchException::forModel($model::class);
            }

            if ($explicit === null) {
                if ($contextId === null) {
                    throw MissingTenantContextException::forModel($model::class);
                }

                $model->setAttribute('tenant_id', $contextId);
            }
        });
    }

    /**
     * When true, queries without a tenant context return no rows in HTTP and tests.
     * Artisan/queue stay unscoped so seeders can pass an explicit tenant_id.
     */
    protected static function tenantScopeFailClosed(): bool
    {
        return true;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
