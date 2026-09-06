<?php

namespace App\Sandbox;

use App\BetaSandbox\FastForward;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

final class DateJump
{
    /**
     * @return array{shifted: int, released: int, declined: int, offers: int, reminders: int, date: string, days: int}
     */
    public function run(Tenant $tenant, string $date): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw SandboxRefusal::because('Pick a date after today. The shop cannot jump backwards.');
        }

        $today = CarbonImmutable::now($tenant->timezone)->startOfDay();
        $target = CarbonImmutable::parse($date, $tenant->timezone)->startOfDay();

        if ($target->lte($today)) {
            throw SandboxRefusal::because('Pick a date after today. The shop cannot jump backwards.');
        }

        $minutes = (int) round(($target->getTimestamp() - $today->getTimestamp()) / 60);
        $result = app(FastForward::class)->advance($tenant, $minutes);

        return [
            ...$result,
            'date' => $target->toDateString(),
            'days' => (int) $today->diffInDays($target),
        ];
    }
}
