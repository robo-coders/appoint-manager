<?php

namespace App\Services\Rebooking;

use App\Models\Booking;
use App\Models\Service;
use App\Models\Subject;

/**
 * How soon this subject is due again.
 *
 * Highest wins:
 *   1. The interval set at checkout for this appointment
 *   2. The subject's own interval
 *   3. The service default (from the service row, seeded from the vertical)
 *
 * A checkout value is written onto the subject so the next visit inherits it.
 */
final class RebookInterval
{
    public function days(?Subject $subject, Service $service, ?int $checkoutDays = null): int
    {
        if ($checkoutDays !== null && $checkoutDays > 0) {
            return $checkoutDays;
        }

        if ($subject !== null && $subject->rebook_interval_days !== null && $subject->rebook_interval_days > 0) {
            return (int) $subject->rebook_interval_days;
        }

        return $service->suggestedIntervalDays();
    }

    public function daysForLastVisit(Subject $subject, Booking $last, Service $service): int
    {
        return $this->days($subject, $service, $last->rebook_interval_days);
    }

    public function remember(Subject $subject, int $days): void
    {
        $subject->forceFill(['rebook_interval_days' => $days])->save();
    }
}
