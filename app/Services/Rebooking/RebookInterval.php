<?php

namespace App\Services\Rebooking;

use App\Models\Booking;
use App\Models\Service;
use App\Models\Subject;

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
