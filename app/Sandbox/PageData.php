<?php

namespace App\Sandbox;

use App\BetaSandbox\FastForward;
use App\BetaSandbox\SampleData;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

final class PageData
{
    /**
     * @return array<string, mixed>
     */
    public static function props(Tenant $tenant): array
    {
        return [
            'shop' => [
                'customers' => $tenant->customers()->count(),
                'bookings' => $tenant->bookings()->count(),
            ],
            'intervals' => array_keys(FastForward::INTERVALS),
            'sizes' => SampleData::sizeOptions(),
            'summary' => SandboxSummary::for($tenant),
            'outbox' => SmsOutbox::list($tenant),
            'candidates' => NoShowSimulator::candidates($tenant),
            'flaky_network' => SandboxState::flaky($tenant),
            'jump_min' => CarbonImmutable::now($tenant->timezone)->addDay()->toDateString(),
        ];
    }
}
