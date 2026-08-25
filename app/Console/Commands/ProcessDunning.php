<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\DunningFailedPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ProcessDunning extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Email unpaid accounts on day 0, 3 and 7, then lock admin writes.';

    public function handle(): int
    {
        $window = (int) config('billing.dunning_days');

        Tenant::query()
            ->where('subscription_status', 'past_due')
            ->whereNotNull('dunning_started_at')
            ->each(function (Tenant $tenant) use ($window): void {
                $days = (int) $tenant->dunning_started_at->startOfDay()->diffInDays(now()->startOfDay());
                $sent = (int) $tenant->dunning_emails_sent;

                if ($days >= 3 && $sent < 2) {
                    $this->mail($tenant, 3);
                    $tenant->forceFill(['dunning_emails_sent' => 2])->save();
                }

                if ($days >= $window && $sent < 3) {
                    $this->mail($tenant, 7);
                    $tenant->forceFill(['dunning_emails_sent' => 3])->save();
                }
            });

        return self::SUCCESS;
    }

    private function mail(Tenant $tenant, int $day): void
    {
        $owners = $tenant->users()->where('role', 'owner')->get();

        if ($owners->isNotEmpty()) {
            Notification::send($owners, new DunningFailedPayment($tenant, $day));
        }
    }
}
