<?php

namespace App\Jobs;

use App\Models\StripeEvent;
use App\Services\Stripe\StripeEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $stripeEventId) {}

    public function handle(StripeEventProcessor $processor): void
    {
        $event = StripeEvent::query()->find($this->stripeEventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $processor->process($event);
        $event->forceFill(['processed_at' => now()])->save();
    }
}
