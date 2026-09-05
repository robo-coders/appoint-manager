<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Loyalty\Loyalty;
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
        'is_loyalty_reward',
    ];

    protected static function booted(): void
    {
        static::creating(function (Booking $booking): void {
            if ($booking->public_token === null) {
                $booking->public_token = (string) Str::uuid();
            }
        });

        /**
         * A completed appointment earns a loyalty stamp, wherever it was
         * completed from.
         *
         * Here rather than in a controller for the reason `Customer::booted()`
         * gives about the phone number: a status that a support script, an
         * import or tinker can also set is a status whose consequence must not
         * live down one route. `Booking::complete()` on `BookingService` is the
         * route an owner uses; this is what makes the other ways agree with it.
         *
         * **`updated`, and only on the transition.** `wasChanged('status')`
         * means a booking that is saved again while already completed does not
         * stamp twice — which is what an edit to its notes would otherwise do.
         * It also means a row *created* as completed does not stamp, and that is
         * deliberate: the CSV importer brings historical appointments in that
         * way, and a salon switching loyalty on should not have last year's
         * bookings hand out free grooms.
         *
         * `Loyalty::stamp()` returns immediately unless the tenant has the
         * feature on, so for everybody else this hook is one enum comparison.
         */
        static::updated(function (Booking $booking): void {
            if ($booking->status !== BookingStatus::Completed || ! $booking->wasChanged('status')) {
                return;
            }

            app(Loyalty::class)->stamp($booking);
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
            'is_loyalty_reward' => 'boolean',
            'deposit_paid_at' => 'datetime',
            'reminder_cancelled_at' => 'datetime',
            'request_expires_at' => 'datetime',
        ];
    }

    /**
     * Is this booking still holding its slot?
     *
     * A no-show answers no, alongside a cancellation and a decline. It used to
     * answer yes, and that was the bug behind "Sorry, that slot was just
     * taken": marking a missed appointment offered the hour to the waitlist and
     * then blocked every one of them from claiming it, because the booking they
     * were being offered a replacement for was still sitting in the slot.
     *
     * See `BookingStatus::vacating()` for why the list lives there rather than
     * here.
     */
    public function occupiesTime(): bool
    {
        return ! in_array($this->status, BookingStatus::vacating(), true);
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
