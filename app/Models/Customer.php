<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'notes',
    ];

    /**
     * Correcting the number is what clears the flag on it.
     *
     * When a chase is rejected enough times, `RebookAttempts` blocks the
     * subject and tells the salon the number looks wrong. The action that
     * follows is her editing the number — so that is the signal, rather than a
     * second button she has to know to press afterwards. Here rather than in
     * `CustomerController` because the number is also editable from the import
     * path and from tinker, and a flag that only clears down one route is a flag
     * that gets stuck.
     */
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sms_opted_out_at' => 'datetime',
        ];
    }

    /**
     * This person has asked us to stop texting them, at this salon.
     *
     * Not fillable, and set only through `SmsConsent`: a consent flag that a
     * mass-assigned customer form could clear is not a consent flag.
     */
    public function smsOptedOut(): bool
    {
        return $this->sms_opted_out_at !== null;
    }

    /**
     * @return HasMany<Subject, $this>
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
