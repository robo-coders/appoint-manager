<?php

namespace App\Services\Rebooking;

use App\Models\Message;
use App\Models\RebookSend;
use App\Models\Subject;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * The one rule that matters: a subject is chased once per due cycle, and the
 * database is what enforces it.
 *
 * `rebooking:send` runs hourly, and a subject who is overdue this morning is
 * overdue this afternoon, tomorrow, and every day until they book. Any rule
 * living in the job's own control flow — "have we sent recently?" followed by
 * "send" — is a read-then-write with a gap in it, and the gap is where a second
 * worker, a manual trigger and a retry after a crash all get their duplicate.
 *
 * So the claim is an INSERT against
 * `unique (tenant_id, subject_id, due_on, attempt)`. Two callers race, one gets
 * a row, the other gets SQLSTATE 23000 and is told no. There is no window.
 *
 * The cycle key is `due_on` — the date the subject fell due — not a timestamp
 * and not a counter. Booking moves the last visit, which moves the due date,
 * which is a new cycle. Nothing else can produce one.
 */
final class RebookAttempts
{
    /**
     * Take the next attempt slot for this subject's current due cycle.
     *
     * Returns null when the subject must not be chased right now, for any of
     * the four reasons this method knows about: the cycle is used up, the
     * follow-up gap has not elapsed, the number has failed too often, or
     * another process got there first.
     */
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
            // 23000 is the integrity-constraint family; the unique index did
            // its job and somebody else is sending this one. Not an error.
            if ($exception->getCode() === '23000') {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Attach the message the claim produced, so a later failure can find its
     * way back here.
     */
    public function attach(RebookSend $claim, ?Message $message, int $segments): void
    {
        $claim->forceFill([
            'message_id' => $message?->id,
            'segments' => $segments,
        ])->save();
    }

    /**
     * The provider would not take it. Give the slot back.
     *
     * Deleting the claim is deliberate: the point of the claim is to stop a
     * duplicate *delivery*, and nothing was delivered. The attempt is not lost
     * — it is in `messages` with status `failed`, which is what the salon sees
     * on the send log, and it is counted on the subject so a permanently dead
     * number stops being dialled.
     */
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
            // The subject was not chased. Put them back on the list so the next
            // run tries again, rather than hiding them behind a contact that
            // never happened.
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

    /**
     * The provider took it and then could not deliver it.
     *
     * The claim stands — we were billed on accept and are not refunded, so
     * pretending the cycle is unspent would let a dead number be charged for
     * twice. But a number that swallows every chase is a number the salon needs
     * to correct, and three cycles of that is enough to say so.
     */
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

    /**
     * The provider took it. A working number clears its own history.
     */
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

    /**
     * The claim a message belongs to, found through the subject rather than
     * through `rebook_sends.message_id`.
     *
     * The message id would be the obvious key and it does not work. The gateway
     * is called from inside the queued job, and on the sync driver — every test,
     * and any deployment without a worker — a provider rejection throws before
     * the caller has had a chance to write the id onto the claim. So the link
     * that has to survive a throw is the one the message itself carries.
     * `rebook_sends.message_id` is kept for the audit trail and is best effort.
     */
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

    /**
     * Too many rejections in a row. The salon needs to correct the number; we
     * are not going to keep paying to find that out.
     */
    public function isBlocked(Subject $subject): bool
    {
        return $subject->rebook_send_blocked_at !== null;
    }

    /**
     * Why this subject will not be chased right now, in words, for the dry run.
     * Null means they will be.
     */
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
