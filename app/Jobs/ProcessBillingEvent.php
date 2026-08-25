<?php

namespace App\Jobs;

use App\Models\BillingEvent;
use App\Services\Billing\BillingEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessBillingEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $billingEventId) {}

    public function handle(BillingEventProcessor $processor): void
    {
        $event = BillingEvent::query()->find($this->billingEventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $processor->process($event);
    }
}
