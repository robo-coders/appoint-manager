<?php

namespace App\Models;

use App\Enums\LoyaltyCardStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LoyaltyEnrolmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyEnrolment extends Model
{
    /** @use HasFactory<LoyaltyEnrolmentFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'customer_id',
        'loyalty_package_id',
        'stamps_used',
        'cycles_completed',
        'status',
        'completed_at',
        'redeemed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stamps_used' => 'integer',
            'cycles_completed' => 'integer',
            'status' => LoyaltyCardStatus::class,
            'completed_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    public function isEarning(): bool
    {
        return $this->package !== null && $this->package->is_active;
    }

    public function rewardDue(): bool
    {
        return $this->isEarning() && $this->stamps_used >= (int) $this->package->sessions_required;
    }

    public function remaining(): int
    {
        if (! $this->isEarning()) {
            return 0;
        }

        return max(0, (int) $this->package->sessions_required - $this->stamps_used);
    }

    public function isFull(): bool
    {
        return $this->status === LoyaltyCardStatus::StampedOut;
    }

    /** @return HasMany<LoyaltyStamp, $this> */
    public function stamps(): HasMany
    {
        return $this->hasMany(LoyaltyStamp::class, 'loyalty_enrolment_id');
    }

    /** @return BelongsTo<LoyaltyPackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(LoyaltyPackage::class, 'loyalty_package_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
