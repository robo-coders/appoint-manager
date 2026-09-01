<?php

namespace App\Services\Rebooking;

use App\Models\Customer;
use App\Models\Subject;
use App\Models\Tenant;
use App\Services\Notifications\Notifier;
use App\Services\Sms\SmsConsent;
use App\Support\SendWindow;
use App\Support\SmsSegments;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Automatic rebooking messages. Off until the operator confirms a dry run.
 *
 * Three gates stand between an overdue subject and a text, and they are
 * deliberately in different places:
 *
 * - **Consent** (`SmsConsent`) — checked here so an opted-out customer is
 *   visibly excluded in the dry run, and again in `Notifier` so nothing can
 *   reach the queue by another route.
 * - **The hour** (`SendWindow`) — checked per tenant, in the tenant's own
 *   timezone.
 * - **Once per cycle** (`RebookAttempts`) — a unique index, not a condition.
 *   Everything above it can be got wrong; that one cannot.
 */
final class RebookMessenger
{
    public function __construct(
        private OverdueSubjects $overdue,
        private Notifier $notifier,
        private RebookAttempts $attempts,
        private SmsConsent $consent,
    ) {}

    public function isEnabled(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings, 'rebooking.messages_enabled', false);
    }

    /**
     * What the next send would do, and what it would cost.
     *
     * @return array{count: int, segments: int, over_one_segment: int, window: string, in_window: bool, book_url: string, book_url_unreachable: bool, messages: list<array<string, mixed>>, suppressed: list<array<string, mixed>>}
     */
    public function dryRun(Tenant $tenant, ?CarbonImmutable $today = null, ?CarbonImmutable $at = null): array
    {
        $rows = $this->overdue->forTenant($tenant, $today);
        $messages = [];
        $suppressed = [];
        $bookUrl = book_url($tenant->slug);

        foreach ($rows as $row) {
            $body = $this->body($tenant, $row);
            $shape = SmsSegments::describe($body);

            $entry = [
                'subject_id' => $row['subject_id'],
                'subject_name' => $row['subject_name'],
                'customer_name' => $row['customer_name'],
                'phone' => $row['phone'],
                'due_label' => $row['due_label'],
                'due_on' => $row['due_on'],
                'body' => $body,
                'segments' => $shape['segments'],
                'encoding' => $shape['encoding'],
                'characters' => $shape['characters'],
            ];

            $reason = $this->suppression($tenant, $row, $at);

            if ($reason !== null) {
                $suppressed[] = $entry + ['reason' => $reason];

                continue;
            }

            $messages[] = $entry;
        }

        $segments = array_sum(array_column($messages, 'segments'));

        return [
            'count' => count($messages),
            'segments' => (int) $segments,
            'over_one_segment' => count(array_filter($messages, fn (array $m) => $m['segments'] > 1)),
            'window' => SendWindow::describe($tenant),
            'in_window' => SendWindow::isOpen($tenant, $at),
            'book_url' => $bookUrl,
            'book_url_unreachable' => booking_url_is_loopback($bookUrl),
            'messages' => $messages,
            'suppressed' => $suppressed,
        ];
    }

    public function enableAfterDryRun(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];
        $settings['rebooking']['messages_enabled'] = true;
        $settings['rebooking']['messages_confirmed_at'] = now()->toIso8601String();
        $tenant->forceFill(['settings' => $settings])->save();
    }

    public function disable(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? [];
        $settings['rebooking']['messages_enabled'] = false;
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /**
     * Send to everyone due who may be sent to. No-op when sending is off or the
     * salon's own clock says it is the wrong time of day.
     *
     * @param  list<int>  $onlySubjectIds  Restrict to these subjects. This is
     *                                     how `rebooking:send --subject=` sends
     *                                     exactly one real text.
     * @param  bool  $ignoreEnabledGate  Send for a tenant that has not turned
     *                                   automatic messages on. Only legitimate
     *                                   alongside `$onlySubjectIds`, which the
     *                                   command enforces: a deliberate one-off
     *                                   test send to a named subject is not the
     *                                   same act as switching the feature on for
     *                                   a salon's whole client base.
     * @return int Messages queued
     */
    public function sendDue(
        Tenant $tenant,
        ?CarbonImmutable $today = null,
        ?CarbonImmutable $at = null,
        array $onlySubjectIds = [],
        bool $ignoreWindow = false,
        bool $ignoreEnabledGate = false,
    ): int {
        if ($ignoreEnabledGate && $onlySubjectIds === []) {
            throw new InvalidArgumentException(
                'Bypassing the enabled gate is only allowed for named subjects.'
            );
        }

        if (! $ignoreEnabledGate && ! $this->isEnabled($tenant)) {
            return 0;
        }

        // Every write below is tenant-scoped and `BelongsToTenant` fails closed
        // without context. The artisan command sets it too; this is here so a
        // direct call — a test, tinker, a future controller — cannot be the one
        // that forgets.
        app(TenantContext::class)->set($tenant);

        $at = $at ?? CarbonImmutable::now();

        // Outside the window nothing is claimed and nothing is dropped: the
        // subject is still overdue at nine tomorrow morning and the next run
        // inside the window sends it.
        if (! $ignoreWindow && ! SendWindow::isOpen($tenant, $at)) {
            return 0;
        }

        $sent = 0;

        foreach ($this->overdue->forTenant($tenant, $today) as $row) {
            if ($onlySubjectIds !== [] && ! in_array((int) $row['subject_id'], $onlySubjectIds, true)) {
                continue;
            }

            $subject = Subject::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereKey($row['subject_id'])
                ->first();

            $customer = $subject?->customer_id
                ? Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($subject->customer_id)->first()
                : null;

            if ($subject === null || $customer === null || $this->consent->isOptedOut($customer)) {
                continue;
            }

            $body = $this->body($tenant, $row);

            // The claim, and the whole duplicate rule. Everything before this
            // line is advisory; this line is the one a second job run, a manual
            // trigger and a crash retry all lose.
            $claim = $this->attempts->claim($tenant, $subject, (string) $row['due_on'], $at);

            if ($claim === null) {
                continue;
            }

            $message = $this->notifier->rebookDue($tenant, $customer, $subject, $body);

            $this->attempts->attach($claim, $message, SmsSegments::count($body));

            $subject->forceFill(['rebook_contacted_at' => $at])->save();

            $sent++;
        }

        return $sent;
    }

    /**
     * The chase, composed from config, sanitised, and carrying its opt-out.
     *
     * The opt-out notice is part of the body rather than something the gateway
     * appends, because it has to be counted in the segment budget. A message
     * that fits in 160 characters until the legally required sentence is added
     * is a two-segment message and we would rather know before we send 200 of
     * them.
     *
     * @param  array<string, mixed>  $row
     */
    public function body(Tenant $tenant, array $row): string
    {
        $template = (string) config('rebooking.message.body');
        $suffix = (string) config('rebooking.message.opt_out_suffix');
        $url = book_url($tenant->slug);

        return SmsSegments::fit(
            (string) $tenant->name,
            fn (string $salon): string => strtr($template, [
                ':salon' => $salon,
                ':subject' => (string) $row['subject_name'],
                ':due' => (string) $row['due_label'],
                ':url' => $url,
            ]).$suffix,
            (int) config('rebooking.message.max_segments', 3),
        );
    }

    /**
     * Why this row will not be texted, in a word the screen can label.
     *
     * @param  array<string, mixed>  $row
     */
    private function suppression(Tenant $tenant, array $row, ?CarbonImmutable $at): ?string
    {
        if (blank($row['phone'])) {
            return 'no_phone';
        }

        $subject = Subject::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($row['subject_id'])
            ->first();

        if ($subject === null) {
            return 'no_phone';
        }

        $customer = $subject->customer_id
            ? Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($subject->customer_id)->first()
            : null;

        if ($customer === null) {
            return 'no_phone';
        }

        if ($this->consent->isOptedOut($customer)) {
            return 'opted_out';
        }

        return $this->attempts->suppressionReason($tenant, $subject, (string) $row['due_on'], $at);
    }
}
