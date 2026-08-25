<?php

namespace App\Console\Commands;

use App\Services\Waitlist\WaitlistOfferer;
use Illuminate\Console\Command;

class ExpireSlotOffers extends Command
{
    protected $signature = 'waitlist:expire-offers';

    protected $description = 'Expire unclaimed slot offers and offer the next batch';

    public function handle(WaitlistOfferer $offerer): int
    {
        $offerer->expireAndContinue();

        return self::SUCCESS;
    }
}
