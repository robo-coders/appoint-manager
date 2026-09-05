<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LoyaltyEnrolmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's progress through one package.
 *
 * `stamps_used` is the current cycle and resets to zero when the free session is
 * taken. `cycles_completed` never resets, which is the difference between "0 of
 * 5" and "0 of 5, and this would be their third free groom" — the second is
 * worth saying to an owner looking at a regular.
 *
 * The package may be null: deleting a package a customer is halfway through
 * nulls the link rather than deleting the record that they were three sessions
 * in. `isEarning()` is what the rest of the code asks instead of assuming.
 */
class LoyaltyEnrolment extends Model
{
    /** @use HasFactory<LoyaltyEnrolmentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * `tenant_id` is fillable here, and it is the only tenant-scoped model in
     * the app where it is.
     *
     * `Loyalty` is handed its tenant as an argument rather than reading ambient
     * context — it runs from a model hook and from the notifier, where there may
     * be none (AUDIT C9) — and enrolment goes through `firstOrCreate`, which is
     * mass assignment and is also what makes the unique index race-safe. Without
     * `tenant_id` fillable that call drops the one column the row cannot be
     * written without.
     *
     * The guard is not weakened: `BelongsToTenant`'s `creating` hook still
     * throws `TenantMismatchException` when an explicit `tenant_id` disagrees
     * with the context, and nothing mass-assigns this model from a request —
     * enrolment is automatic and has no form behind it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'loyalty_package_id',
        'stamps_used',
        'cycles_completed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stamps_used' => 'integer',
            'cycles_completed' => 'integer',
        ];
    }

    /**
     * Whether this enrolment is attached to a package that is still collecting.
     *
     * False when the package has been deleted or switched off, in which case the
     * enrolment is a record of past progress and nothing more — no stamp is
     * added and no reward is due.
     */
    public function isEarning(): bool
    {
        return $this->package !== null && $this->package->is_active;
    }

    /** Whether the stamps are full and the next appointment is the free one. */
    public function rewardDue(): bool
    {
        return $this->isEarning() && $this->stamps_used >= (int) $this->package->sessions_required;
    }

    /**
     * How many more sessions before the free one. Zero when it is due.
     */
    public function remaining(): int
    {
        if (! $this->isEarning()) {
            return 0;
        }

        return max(0, (int) $this->package->sessions_required - $this->stamps_used);
    }

    /**
     * @return BelongsTo<LoyaltyPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPackage::class, 'loyalty_package_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
