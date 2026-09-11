<?php

namespace App\Models;

use App\Enums\LoyaltyStampMethod;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LoyaltyStampFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyStamp extends Model
{
    /** @use HasFactory<LoyaltyStampFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'loyalty_enrolment_id',
        'booking_id',
        'method',
        'stamped_by',
        'visit_date',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => LoyaltyStampMethod::class,
            'visit_date' => 'date',
        ];
    }

    /** @return Attribute<string, never> */
    protected function impression(): Attribute
    {
        return Attribute::get(fn (): string => $this->visit_date->format('j M'));
    }

    /** @return BelongsTo<LoyaltyEnrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(LoyaltyEnrolment::class, 'loyalty_enrolment_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function stampedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stamped_by');
    }
}
