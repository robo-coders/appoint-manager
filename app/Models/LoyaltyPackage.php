<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LoyaltyPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A loyalty package: a count, and what happens when it is reached.
 *
 * One per tenant in v1, and the settings screen only offers one — but this is a
 * row rather than a column on `tenants` so a second tier is a second row rather
 * than a migration with a backfill. `is_active` is how "the current package"
 * becomes a query.
 */
class LoyaltyPackage extends Model
{
    /** @use HasFactory<LoyaltyPackageFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sessions_required',
        'reward',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sessions_required' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LoyaltyEnrolment, $this>
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(LoyaltyEnrolment::class);
    }
}
