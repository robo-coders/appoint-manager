<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'staff_id',
        'service_id',
        'customer_id',
        'subject_id',
        'starts_at',
        'ends_at',
        'status',
        'deposit_status',
        'price_at_booking',
        'deposit_at_booking',
        'public_token',
        'cancelled_at',
        'cancellation_reason',
        'source',
        'rebook_interval_days',
        'stripe_payment_intent_id',
        'waitlist_entry_id',
        'request_expires_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            if ($booking->public_token === null) {
                $booking->public_token = (string) Str::uuid();
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
            'cancelled_at' => 'datetime',
            'status' => BookingStatus::class,
            'deposit_status' => DepositStatus::class,
            'source' => BookingSource::class,
            'rebook_interval_days' => 'integer',
            'price_at_booking' => MoneyCast::class,
            'deposit_at_booking' => MoneyCast::class,
            'deposit_paid_at' => 'datetime',
            'reminder_cancelled_at' => 'datetime',
            'request_expires_at' => 'datetime',
        ];
    }

    public function occupiesTime(): bool
    {
        return ! in_array($this->status, [BookingStatus::Cancelled, BookingStatus::Declined], true);
    }

    public function isBookingRequest(): bool
    {
        return $this->status === BookingStatus::Pending && $this->request_expires_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<WaitlistEntry, $this>
     */
    public function waitlistEntry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class);
    }
}
