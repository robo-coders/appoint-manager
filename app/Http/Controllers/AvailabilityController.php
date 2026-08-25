<?php

namespace App\Http\Controllers;

use App\Enums\Weekday;
use App\Http\Requests\Availability\SyncAvailabilityRequest;
use App\Models\AvailabilityRule;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', AvailabilityRule::class);

        $staff = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'colour']);

        $rules = AvailabilityRule::query()
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(fn (AvailabilityRule $rule) => [
                'id' => $rule->id,
                'user_id' => $rule->user_id,
                'weekday' => $rule->weekday->value,
                'start_time' => substr((string) $rule->start_time, 0, 5),
                'end_time' => substr((string) $rule->end_time, 0, 5),
            ]);

        return Inertia::render('Availability/Index', [
            'staff' => $staff,
            'rules' => $rules,
        ]);
    }

    public function sync(SyncAvailabilityRequest $request, User $staff): RedirectResponse
    {
        $this->authorize('update', $staff);

        DB::transaction(function () use ($request, $staff) {
            AvailabilityRule::query()->where('user_id', $staff->id)->delete();

            foreach ($request->validated('ranges') as $range) {
                AvailabilityRule::query()->create([
                    'user_id' => $staff->id,
                    'weekday' => Weekday::from((int) $range['weekday']),
                    'start_time' => $range['start_time'].':00',
                    'end_time' => $range['end_time'].':00',
                ]);
            }
        });

        return redirect()->route('availability.index')->with('toast', 'Hours saved.');
    }
}
