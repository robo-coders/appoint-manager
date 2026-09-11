<?php

namespace App\BetaSandbox;

use App\Enums\BookingStatus;
use App\Enums\MessageType;
use App\Models\Booking;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Booking\BookingService;
use App\Services\Notifications\Notifier;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

final class FastForward
{
    public const INTERVALS = [
        'day' => 1440,
        'week' => 10080,
    ];

    public function __construct(
        private BookingService $bookings,
        private WaitlistOfferer $waitlist,
        private Notifier $notifier,
    ) {}

    /**
     * @param  'day'|'week'  $interval
     * @return array{shifted: int, released: int, declined: int, offers: int, reminders: int}
     */
    public function run(Tenant $tenant, string $interval): array
    {
        $minutes = self::INTERVALS[$interval] ?? null;

        abort_if($minutes === null, 422);

        return $this->advance($tenant, $minutes);
    }

    /** @return array{shifted: int, released: int, declined: int, offers: int, reminders: int} */
    public function advance(Tenant $tenant, int $minutes): array
    {
        BetaSandbox::guard($tenant);

        abort_if($minutes < 1, 422);

        $context = app(TenantContext::class);
        $previous = $context->tenant();
        $context->set($tenant);

        try {
            return SandboxMute::while(function () use ($tenant, $minutes): array {
                $shifted = DB::transaction(fn (): int => $this->shift($tenant, $minutes));

                return [
                    'shifted' => $shifted,
                    'released' => $this->releaseExpiredHolds($tenant),
                    'declined' => $this->declineExpiredRequests($tenant),
                    'offers' => $this->expireSlotOffers($tenant),
                    'reminders' => $this->sendDueReminders($tenant),
                ];
            });
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    public function remindDue(Tenant $tenant): int
    {
        BetaSandbox::guard($tenant);

        $context = app(TenantContext::class);
        $previous = $context->tenant();
        $context->set($tenant);

        try {
            return SandboxMute::while(fn (): int => $this->sendDueReminders($tenant));
        } finally {
            $previous === null ? $context->clear() : $context->set($previous);
        }
    }

    private function shift(Tenant $tenant, int $minutes): int
    {
        $seconds = $minutes * 60;
        $days = intdiv($minutes, 1440);
        $rows = 0;

        foreach (SandboxTables::shiftable() as $table => $columns) {
            $updates = [];

            foreach ($columns as $column) {
                $updates[$column] = DB::raw("DATE_SUB(`{$column}`, INTERVAL {$seconds} SECOND)");
            }

            $rows += DB::table($table)->where('tenant_id', $tenant->id)->update($updates);
        }

        foreach (SandboxTables::shiftableDates() as $table => $columns) {
            $updates = [];

            foreach ($columns as $column) {
                $updates[$column] = DB::raw("DATE_SUB(`{$column}`, INTERVAL {$days} DAY)");
            }

            $rows += DB::table($table)->where('tenant_id', $tenant->id)->update($updates);
        }

        return $rows;
    }

    private function releaseExpiredHolds(Tenant $tenant): int
    {
        $cutoff = now()->subMinutes((int) config('booking.pending_hold_minutes'));

        $expired = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Pending->value)
            ->whereNull('request_expires_at')
            ->where('created_at', '<=', $cutoff)
            ->get();

        foreach ($expired as $booking) {
            $this->bookings->cancel($booking, 'checkout_expired');
        }

        return $expired->count();
    }

    private function declineExpiredRequests(Tenant $tenant): int
    {
        $expired = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('request_expires_at')
            ->where('request_expires_at', '<=', now())
            ->get();

        foreach ($expired as $booking) {
            $this->bookings->decline($booking, null, null, 'booking.request.expired');
        }

        return $expired->count();
    }

    private function expireSlotOffers(Tenant $tenant): int
    {
        $due = DB::table('slot_offers')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'sent')
            ->where('expires_at', '<=', now())
            ->count();

        $this->waitlist->expireAndContinue($tenant->id);

        return $due;
    }

    private function sendDueReminders(Tenant $tenant): int
    {
        $window = now()->addHours((int) config('booking.reminder_hours'));

        $due = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', BookingStatus::Confirmed->value)
            ->whereNull('reminder_cancelled_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', $window)
            ->get();

        $sent = 0;

        foreach ($due as $booking) {
            $already = Message::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('booking_id', $booking->id)
                ->where('type', MessageType::Reminder->value)
                ->exists();

            if ($already) {
                continue;
            }

            $this->notifier->reminder($booking);
            $sent++;
        }

        return $sent;
    }
}
