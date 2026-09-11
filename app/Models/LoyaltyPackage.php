<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LoyaltyPackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyPackage extends Model
{
    /** @use HasFactory<LoyaltyPackageFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'sessions_required',
        'reward',
        'is_active',
        'eligible_service_id',
        'auto_stamp',
        'auto_enrol',
        'auto_apply_reward',
        'show_visit_date',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sessions_required' => 'integer',
            'is_active' => 'boolean',
            'auto_stamp' => 'boolean',
            'auto_enrol' => 'boolean',
            'auto_apply_reward' => 'boolean',
            'show_visit_date' => 'boolean',
        ];
    }

    public function coversService(?int $serviceId): bool
    {
        return $this->eligible_service_id === null
            || (int) $this->eligible_service_id === (int) $serviceId;
    }

    /** @return BelongsTo<Service, $this> */
    public function eligibleService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'eligible_service_id');
    }

    /** @return HasMany<LoyaltyEnrolment, $this> */
    public function enrolments(): HasMany
    {
        return $this->hasMany(LoyaltyEnrolment::class);
    }
}
