<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Services\Rebooking\OverdueSubjects;
use App\Services\Rebooking\RebookMessenger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverdueController extends Controller
{
    public function index(OverdueSubjects $overdue, RebookMessenger $messages): Response
    {
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $rows = $overdue->forTenant($tenant);
        $summary = $overdue->summary($tenant);
        $previewing = session()->get('rebooking_preview') === true;

        return Inertia::render('Overdue/Index', [
            'summary' => $summary,
            'rows' => $rows,
            'stopped' => $overdue->stoppedForTenant($tenant),
            'messages_enabled' => $messages->isEnabled($tenant),
            'dry_run' => $previewing ? $messages->dryRun($tenant) : null,
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
        ])->save();

        return back()->with('toast', 'Chasing again.');
    }

    private function guard(Subject $subject): void
    {
        abort_unless(current_tenant()?->id === $subject->tenant_id, 404);
    }
}
