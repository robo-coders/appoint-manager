<?php

namespace App\Http\Controllers\BetaSandbox;

use App\BetaSandbox\BetaSandbox;
use App\BetaSandbox\FastForward;
use App\BetaSandbox\SampleData;
use App\BetaSandbox\SandboxNotReady;
use App\BetaSandbox\SandboxReset;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Sandbox\PageData;
use App\Sandbox\SandboxState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SandboxController extends Controller
{
    public function show(): Response
    {
        $tenant = $this->tenant();

        return Inertia::render('Settings/Sandbox/Index', PageData::props($tenant));
    }

    public function sampleData(Request $request, SampleData $sample): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $size = (string) $request->input('size', 'typical');

        abort_unless(array_key_exists($size, SampleData::SIZES), 422);

        try {
            $counts = $sample->load($tenant, $size);
        } catch (SandboxNotReady $exception) {
            return back()->withErrors(['sandbox' => $exception->getMessage()]);
        }

        $label = collect(SampleData::sizeOptions())->firstWhere('key', $size)['label'] ?? 'Sample shop';
        SandboxState::put($tenant->fresh(), ['sample_size' => $size]);
        SandboxState::remember($tenant->fresh(), 'Loaded '.$label);

        return back()->with('toast', sprintf(
            '%s loaded: %d customers, %d appointments, %d people on the waitlist.',
            $label,
            $counts['customers'],
            $counts['bookings'],
            $counts['waitlist'],
        ));
    }

    public function fastForward(Request $request, FastForward $forward): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $interval = (string) $request->input('interval');

        abort_unless(array_key_exists($interval, FastForward::INTERVALS), 422);

        $result = $forward->run($tenant, $interval);
        $label = $interval === 'week' ? 'Skipped 1 week' : 'Skipped 1 day';
        SandboxState::remember($tenant->fresh(), $label);

        return back()->with('toast', $this->fastForwardMessage($interval, $result));
    }

    public function reset(Request $request, SandboxReset $reset): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $removed = $reset->run($tenant);
        SandboxState::remember($tenant->fresh(), 'Reset my shop');

        return back()->with('toast', sprintf(
            'Shop reset. %d customers and %d appointments removed. Your staff, services and hours are untouched.',
            $removed['customers'] ?? 0,
            $removed['bookings'] ?? 0,
        ));
    }

    private function tenant(?Request $request = null): Tenant
    {
        $tenant = BetaSandbox::guard(current_tenant());

        if ($request !== null) {
            $this->rejectTampering($request, $tenant);
        }

        return $tenant;
    }

    private function rejectTampering(Request $request, Tenant $tenant): void
    {
        foreach (['tenant_id', 'tenant', 'tenant_slug'] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $value = (string) $request->input($field);

            abort_unless(
                $value === (string) $tenant->id || $value === (string) $tenant->slug,
                403,
                'A sandbox action can only be run on your own shop.',
            );
        }
    }

    /** @param  array{shifted: int, released: int, declined: int, offers: int, reminders: int}  $result */
    private function fastForwardMessage(string $interval, array $result): string
    {
        $moved = $interval === 'week' ? 'a week' : 'a day';
        $message = 'Your shop moved forward '.$moved.'.';

        $happened = [];

        if ($result['reminders'] > 0) {
            $happened[] = $result['reminders'].' '.($result['reminders'] === 1 ? 'reminder' : 'reminders').' went out';
        }

        if ($result['released'] > 0) {
            $happened[] = $result['released'].' unpaid '.($result['released'] === 1 ? 'hold' : 'holds').' released';
        }

        if ($result['declined'] > 0) {
            $happened[] = $result['declined'].' '.($result['declined'] === 1 ? 'request' : 'requests').' expired';
        }

        if ($result['offers'] > 0) {
            $happened[] = $result['offers'].' waitlist '.($result['offers'] === 1 ? 'offer' : 'offers').' ran out';
        }

        if ($happened === []) {
            return $message.' Nothing was waiting to happen.';
        }

        return $message.' '.ucfirst(implode(', ', $happened)).'.';
    }
}
