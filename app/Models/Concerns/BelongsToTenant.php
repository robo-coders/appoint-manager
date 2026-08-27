<?php

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContextException;
use App\Exceptions\TenantMismatchException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
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
            if ($model instanceof User && $model->is_super_admin) {
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
     * When true, a query with no tenant context returns no rows — everywhere,
     * including artisan commands and queue workers. There is no console
     * exemption any more (AUDIT C9); code that legitimately spans tenants says
     * so with `withoutGlobalScopes()` and an explicit `tenant_id`.
     *
     * Nothing overrides this. `User` used to — login and password reset have to
     * find a person before anyone knows their tenant — and that one override
     * made `User` the only model a route could bind with no context and still
     * find, which is how `/staff/{staff}` reached `StaffPolicy` holding another
     * salon's row. The exemption now lives on the auth surface that needs it,
     * in `App\Auth\IdentityUserProvider`, and nowhere else.
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
