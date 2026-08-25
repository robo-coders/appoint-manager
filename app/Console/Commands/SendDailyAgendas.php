<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Services\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendDailyAgendas extends Command
{
    protected $signature = 'bookings:daily-agenda';

    protected $description = 'Email each salon tomorrow’s bookings at 7am local time';

    public function handle(Notifier $notifier): int
    {
        Tenant::query()->whereNotNull('onboarding_completed_at')->each(function (Tenant $tenant) use ($notifier) {
            $now = CarbonImmutable::now($tenant->timezone);

            if ($now->hour !== 7) {
                return;
            }

            $from = $now->addDay()->startOfDay()->utc();
            $to = $now->addDay()->addDay()->startOfDay()->utc();

            $bookings = Booking::withoutGlobalScopes()
                ->with(['customer', 'service', 'staff'])
                ->where('tenant_id', $tenant->id)
                ->where('status', BookingStatus::Confirmed->value)
                ->where('starts_at', '>=', $from)
                ->where('starts_at', '<', $to)
                ->orderBy('starts_at')
                ->get();

            $notifier->dailyAgenda($tenant, $bookings);
        });

        return self::SUCCESS;
    }
}
