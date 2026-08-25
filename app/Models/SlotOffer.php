<?php

namespace App\Models;

use App\Enums\SlotOfferStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SlotOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SlotOffer extends Model
{
    /** @use HasFactory<SlotOfferFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'waitlist_entry_id',
        'booking_id',
        'starts_at',
        'ends_at',
        'service_id',
        'staff_id',
        'token',
        'status',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (SlotOffer $offer): void {
            if ($offer->token === null) {
                $offer->token = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'expires_at' => 'datetime',
            'status' => SlotOfferStatus::class,
        ];
    }

    public function isClaimable(): bool
    {
        return $this->status === SlotOfferStatus::Sent && $this->expires_at?->isFuture();
    }

    /**
     * @return BelongsTo<WaitlistEntry, $this>
     */
    public function waitlistEntry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
