<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'notes',
    ];

    protected static function booted(): void
    {
        static::updated(function (Customer $customer): void {
            if (! $customer->wasChanged('phone')) {
                return;
            }

            Subject::withoutGlobalScopes()
                ->where('tenant_id', $customer->tenant_id)
                ->where('customer_id', $customer->id)
                ->whereNotNull('rebook_send_blocked_at')
                ->update(['rebook_send_blocked_at' => null, 'rebook_failed_sends' => 0]);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sms_opted_out_at' => 'datetime',
            'requires_full_payment_override' => 'boolean',
            'suggested_rule_dismissed_at' => 'datetime',
            'notes_updated_at' => 'datetime',
        ];
    }

    public function smsOptedOut(): bool
    {
        return $this->sms_opted_out_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function notesEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notes_updated_by');
    }

    /** @return HasMany<Subject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
