<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Rebooking\RebookMessenger;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class SendRebookReminders extends Command
{
    protected $signature = 'rebooking:send';

    protected $description = 'Send due rebooking messages for tenants that have confirmed sending.';

    public function handle(RebookMessenger $messages, TenantContext $context): int
    {
        Tenant::query()->each(function (Tenant $tenant) use ($messages, $context): void {
            $context->set($tenant);
            $sent = $messages->sendDue($tenant);

            if ($sent > 0) {
                $this->info("{$tenant->slug}: {$sent}");
            }
        });

        $context->clear();

        return self::SUCCESS;
    }
}
