<?php

namespace App\Http\Controllers;

use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Message;
use App\Models\Subject;
use App\Models\Tenant;
use App\Services\Rebooking\OverdueSubjects;
use App\Services\Rebooking\RebookMessenger;
use App\Support\ContactVisibility;
use App\Support\MaskedContact;
use App\Support\SendWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverdueController extends Controller
{
    public function index(OverdueSubjects $overdue, RebookMessenger $messages, Request $request): Response
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $rows = $overdue->forTenant($tenant);
        $summary = $overdue->summary($tenant);
        $previewing = session()->get('rebooking_preview') === true;

        /*
         * The masking happens here rather than in `OverdueSubjects`, because
         * that service has a second caller: `RebookMessenger` reads `phone` off
         * the same rows to actually send the text. Masking at the source would
         * send eight bullet points to Twilio.
         */
        $contacts = ContactVisibility::for($request->user());
        $dryRun = $previewing ? $messages->dryRun($tenant) : null;

        return Inertia::render('Overdue/Index', [
            'summary' => $summary,
            'rows' => $this->maskRows($rows, $contacts),
            // `stopped` and `snoozed` carry no number — subject, customer name
            // and a date — so there is nothing on them to mask.
            'stopped' => $overdue->stoppedForTenant($tenant),
            'snoozed' => $overdue->snoozedForTenant($tenant),
            'messages_enabled' => $messages->isEnabled($tenant),
            'dry_run' => $dryRun === null ? null : [
                ...$dryRun,
                'messages' => $this->maskRows($dryRun['messages'] ?? [], $contacts),
                'suppressed' => $this->maskRows($dryRun['suppressed'] ?? [], $contacts),
            ],
            'window' => SendWindow::describe($tenant),
            'timezone' => $tenant->timezone,
            'recent_sends' => $this->recentSends($tenant, $contacts),
            'noun' => $tenant->vertical()['subject_singular'] ?? 'subject',
            'noun_plural' => $tenant->vertical()['subject_plural'] ?? 'subjects',
        ]);
    }

    public function previewEnable(RebookMessenger $messages): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $messages->dryRun($tenant);
        session()->put('rebooking_preview', true);

        return back();
    }

    public function enable(RebookMessenger $messages): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        if (session()->get('rebooking_preview') !== true) {
            return redirect()->route('overdue.index')->with('toast', 'Preview the messages first. Nothing has been turned on.');
        }

        $messages->enableAfterDryRun($tenant);
        session()->forget('rebooking_preview');

        return redirect()->route('overdue.index')->with('toast', 'Automatic messages are on. The next run will send what you just reviewed.');
    }

    public function disable(RebookMessenger $messages): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $messages->disable($tenant);

        return back()->with('toast', 'Automatic messages are off.');
    }

    public function contacted(Subject $subject): RedirectResponse
    {
        $this->guard($subject);
        $subject->forceFill(['rebook_contacted_at' => now()])->save();

        return back()->with('toast', 'Marked as contacted.');
    }

    public function snooze(Request $request, Subject $subject): RedirectResponse
    {
        $this->guard($subject);

        $days = max(1, min(365, $request->integer('days', 14)));
        $subject->forceFill(['rebook_snoozed_until' => CarbonImmutable::now()->addDays($days)])->save();

        return back()->with('toast', 'Snoozed.');
    }

    public function stop(Subject $subject): RedirectResponse
    {
        $this->guard($subject);
        $subject->forceFill(['rebook_stopped_at' => now()])->save();

        return back()->with('toast', 'Stopped chasing. History is still here.');
    }

    public function resume(Subject $subject): RedirectResponse
    {
        $this->guard($subject);
        $subject->forceFill([
            'rebook_stopped_at' => null,
            'rebook_snoozed_until' => null,
            'rebook_contacted_at' => null,
            // Starting again means starting again. A subject blocked because
            // their number kept failing gets a clean slate too, or "chase this
            // one again" would quietly not.
            'rebook_failed_sends' => 0,
            'rebook_send_blocked_at' => null,
        ])->save();

        return back()->with('toast', 'Chasing again.');
    }

    /**
     * The chases that actually went out, and what became of them.
     *
     * The salon sees delivery failures here or nowhere. A message Twilio
     * accepted and then could not deliver is currently billed and invisible,
     * which means she believes she chased somebody she did not — and the fix,
     * correcting a mistyped number, is one she can only make if she is told.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Blank the number on every row this person may not read it on.
     *
     * `customer_id` is the key rather than `subject_id`: the permission is
     * about the person you would be ringing, and one customer's three dogs are
     * three rows here.
     *
     * @template T of iterable<int, array<string, mixed>>
     *
     * @param  T  $rows
     * @return list<array<string, mixed>>
     */
    private function maskRows(iterable $rows, ContactVisibility $contacts): array
    {
        $out = [];

        foreach ($rows as $row) {
            $visible = $contacts->customer($row['customer_id'] ?? null);

            $out[] = $visible
                ? [...$row, 'contact_hidden' => false]
                : [...$row, 'phone' => MaskedContact::phone($row['phone'] ?? null), 'contact_hidden' => true];
        }

        return $out;
    }

    private function recentSends(Tenant $tenant, ContactVisibility $contacts): array
    {
        return Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', MessageType::RebookDue->value)
            ->where('channel', MessageChannel::Sms->value)
            ->with(['customer' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->limit((int) config('rebooking.send_log_rows', 20))
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                // `to` is the number the text went to, which is the customer's.
                'to' => $contacts->customer($message->customer_id)
                    ? $message->to
                    : MaskedContact::phone($message->to),
                'contact_hidden' => ! $contacts->customer($message->customer_id),
                'customer_name' => $message->customer?->name,
                'sent_on' => $message->created_at?->timezone($tenant->timezone)->format('j M H:i'),
                'status' => $message->status->value,
                'failed' => in_array($message->status, [MessageStatus::Failed, MessageStatus::Undelivered], true),
                'segments' => (int) ($message->segments ?: 1),
                'error' => $message->provider_error,
            ])
            ->all();
    }

    private function guard(Subject $subject): void
    {
        abort_unless(current_tenant()?->id === $subject->tenant_id, 404);
    }
}
