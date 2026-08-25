<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * "This id exists, and it belongs to the tenant making the request."
 *
 * Laravel's built-in `exists` rule runs on the bare query builder, so it never sees
 * the tenant global scope. That made every `Rule::exists(User::class, 'id')` accept
 * ids from other tenants, which was enough to attach a competitor's staff member to
 * your service or book time off against their diary. Use this instead — always.
 *
 * @see \Tests\Feature\Tenancy\TenantScopedValidationTest
 */
class ExistsForTenant implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private string $model,
        private string $column = 'id',
        private ?int $tenantId = null,
    ) {}

    /**
     * @param  class-string<Model>  $model
     */
    public static function of(string $model, string $column = 'id'): self
    {
        return new self($model, $column);
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        $tenantId = $this->tenantId ?? current_tenant_id();

        if ($tenantId === null) {
            $fail('The selected :attribute is invalid.');

            return;
        }

        /** @var Model $instance */
        $instance = new $this->model;

        $query = DB::table($instance->getTable())
            ->where($this->column, $value)
            ->where('tenant_id', $tenantId);

        if (in_array(SoftDeletes::class, class_uses_recursive($this->model), true)) {
            $query->whereNull('deleted_at');
        }

        if (! $query->exists()) {
            $fail('The selected :attribute is invalid.');
        }
    }
}
