<?php

namespace App\Services\Rebooking;

use App\Models\Message;
use App\Models\RebookSend;
use App\Models\Subject;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

final class RebookAttempts
{
    public function claim(Tenant $tenant, Subject $subject, string $dueOn, ?CarbonImmutable $at = null): ?RebookSend
    {
        $at = $at ?? CarbonImmutable::now();
        $max = $this->maxPerCycle();

        if ($this->isBlocked($subject)) {
            return null;
        }

        $existing = RebookSend::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('subject_id', $subject->id)
            ->where('due_on', $dueOn)
            ->orderByDesc('attempt')
            ->get();

        if ($existing->count() >= $max) {
            return null;
        }

        if ($existing->isNotEmpty()) {
            $last = $existing->first();
            $gap = (int) config('rebooking.attempts.follow_up_gap_days');

            if ($last->sent_at === null || $at->lt(CarbonImmutable::parse($last->sent_at)->addDays($gap))) {
                return null;
            }
        }

        $attempt = $existing->isEmpty() ? 1 : ((int) $existing->first()->attempt) + 1;

        try {
            $claim = new RebookSend;
            $claim->forceFill([
                'tenant_id' => $tenant->id,
                'subject_id' => $subject->id,
                'customer_id' => $subject->customer_id,
                'due_on' => $dueOn,
                'attempt' => $attempt,
                'segments' => 0,
                'sent_at' => $at,
            ]);
            $claim->save();

            return $claim;
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000') {
                return null;
            }

            throw $exception;
        }
    }

    public function attach(RebookSend $claim, ?Message $message, int $segments): void
    {
        $claim->forceFill([
            'message_id' => $message?->id,
            'segments' => $segments,
        ])->save();
    }

    public function release(Message $message): void
    {
        $claim = $this->claimFor($message);

        if ($claim === null) {
            return;
        }

        $subject = Subject::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->whereKey($claim->subject_id)
            ->first();

        $claim->delete();

        if ($subject === null) {
            return;
        }

        $failures = ((int) $subject->rebook_failed_sends) + 1;
        $limit = max(1, (int) config('rebooking.attempts.max_send_failures'));

        $subject->forceFill([
            'rebook_failed_sends' => min(255, $failures),
            'rebook_contacted_at' => null,
            'rebook_send_blocked_at' => $failures >= $limit ? ($subject->rebook_send_blocked_at ?? now()) : null,
        ])->save();

        if ($failures >= $limit) {
            Log::warning('Rebooking sends blocked for subject after repeated provider rejection', [
                'tenant_id' => $message->tenant_id,
                'subject_id' => $subject->id,
                'failures' => $failures,
            ]);
        }
    }

    public function reportUndelivered(Message $message): void
    {
        $claim = $this->claimFor($message);

        if ($claim === null) {
            return;
        }

        $subject = Subject::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->whereKey($claim->subject_id)
            ->first();

        if ($subject === null) {
            return;
        }

        $failures = ((int) $subject->rebook_failed_sends) + 1;
        $limit = max(1, (int) config('rebooking.attempts.max_send_failures'));

        $subject->forceFill([
            'rebook_failed_sends' => min(255, $failures),
            'rebook_send_blocked_at' => $failures >= $limit ? ($subject->rebook_send_blocked_at ?? now()) : null,
        ])->save();
    }

    public function succeeded(Message $message): void
    {
        $claim = $this->claimFor($message);

        if ($claim === null) {
            return;
        }

        Subject::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->whereKey($claim->subject_id)
            ->update(['rebook_failed_sends' => 0, 'rebook_send_blocked_at' => null]);
    }

    private function claimFor(Message $message): ?RebookSend
    {
        if ($message->subject_id === null || ! $message->type?->isMarketing()) {
            return null;
        }

        return RebookSend::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->where('subject_id', $message->subject_id)
            ->orderByDesc('due_on')
            ->orderByDesc('attempt')
            ->first();
    }

    public function isBlocked(Subject $subject): bool
    {
        return $subject->rebook_send_blocked_at !== null;
    }

    public function suppressionReason(Tenant $tenant, Subject $subject, string $dueOn, ?CarbonImmutable $at = null): ?string
    {
        $at = $at ?? CarbonImmutable::now();

        if ($this->isBlocked($subject)) {
            return 'number_failing';
        }

        $sends = RebookSend::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('subject_id', $subject->id)
            ->where('due_on', $dueOn)
            ->orderByDesc('attempt')
            ->get();

        if ($sends->isEmpty()) {
            return null;
        }

        if ($sends->count() >= $this->maxPerCycle()) {
            return 'attempts_used';
        }

        $gap = (int) config('rebooking.attempts.follow_up_gap_days');

        if ($at->lt(CarbonImmutable::parse($sends->first()->sent_at)->addDays($gap))) {
            return 'awaiting_follow_up';
        }

        return null;
    }

    private function maxPerCycle(): int
    {
        return max(1, (int) config('rebooking.attempts.max_per_cycle'));
    }
}
