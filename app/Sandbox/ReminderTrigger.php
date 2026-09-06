<?php

namespace App\Sandbox;

use App\BetaSandbox\FastForward;
use App\Models\Tenant;

final class ReminderTrigger
{
    public function run(Tenant $tenant): int
    {
        return app(FastForward::class)->remindDue($tenant);
    }
}
