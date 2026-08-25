<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimeOff\StoreTimeOffRequest;
use App\Models\TimeOff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TimeOffController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', TimeOff::class);

        $timezone = current_tenant()?->timezone ?? 'UTC';

        $entries = TimeOff::query()
            ->with('user')
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (TimeOff $entry) => [
                'id' => $entry->id,
                'user_id' => $entry->user_id,
                'user_name' => $entry->user?->name,
                'starts_at' => $entry->starts_at?->utc()->toIso8601String(),
                'ends_at' => $entry->ends_at?->utc()->toIso8601String(),
                'starts_at_local' => $entry->starts_at?->timezone($timezone)->format('Y-m-d H:i'),
                'ends_at_local' => $entry->ends_at?->timezone($timezone)->format('Y-m-d H:i'),
                'reason' => $entry->reason,
                'is_all_day' => $entry->is_all_day,
            ]);

        return Inertia::render('TimeOff/Index', [
            'entries' => $entries,
            'staff' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'timezone' => $timezone,
        ]);
    }

    public function store(StoreTimeOffRequest $request): RedirectResponse
    {
        $timezone = current_tenant()?->timezone ?? 'UTC';
        [$startsAt, $endsAt] = $this->bounds($request, $timezone);

        if ($endsAt->lte($startsAt)) {
            return back()->withErrors([
                'ends_on' => 'End must be after start.',
            ]);
        }

        TimeOff::query()->create([
            'user_id' => $request->integer('user_id'),
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
            'is_all_day' => $request->boolean('is_all_day'),
            'reason' => $request->input('reason'),
        ]);

        return redirect()->route('time-off.index')->with('toast', 'Time off saved.');
    }

    public function destroy(TimeOff $timeOff): RedirectResponse
    {
        $this->authorize('delete', $timeOff);

        $timeOff->delete();

        return redirect()->route('time-off.index')->with('toast', 'Time off removed.');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(StoreTimeOffRequest $request, string $timezone): array
    {
        if ($request->boolean('is_all_day')) {
            $startsAt = Carbon::parse($request->string('starts_on')->toString(), $timezone)->startOfDay();
            $endsAt = Carbon::parse($request->string('ends_on')->toString(), $timezone)->addDay()->startOfDay();

            return [$startsAt, $endsAt];
        }

        $startsAt = Carbon::parse(
            $request->string('starts_on')->toString().' '.$request->string('start_time')->toString(),
            $timezone,
        );
        $endsAt = Carbon::parse(
            $request->string('ends_on')->toString().' '.$request->string('end_time')->toString(),
            $timezone,
        );

        return [$startsAt, $endsAt];
    }
}
