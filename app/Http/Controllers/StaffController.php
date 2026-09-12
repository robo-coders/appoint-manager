<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\AvailabilityRule;
use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use App\Support\StaffServices;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    private const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $staff = User::query()
            ->with('services:id,name,is_active')
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $services = StaffServices::active();

        $rules = AvailabilityRule::query()
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->groupBy('user_id');

        $booked = $this->bookedThisWeek($tenant->timezone);

        return Inertia::render('Staff/Index', [
            'services' => $services
                ->map(fn (Service $service) => ['id' => $service->id, 'name' => $service->name])
                ->values()
                ->all(),
            'staff' => $staff->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_bookable' => $user->is_bookable,
                'is_active' => $user->is_active,
                'can_see_customer_contacts' => $user->isOwner() || $user->can_see_customer_contacts,
                'colour' => $user->colour,
                'initial' => Str::upper(Str::substr(trim($user->name), 0, 1)),
                'role_label' => $this->roleLabel($user),
                'hours' => $this->hoursSummary($rules->get($user->id) ?? collect()),
                'weekly_hours' => $this->weeklyHours($rules->get($user->id) ?? collect()),
                'daily_hours' => $this->dailyHours($rules->get($user->id) ?? collect()),
                'booked_this_week' => (int) ($booked[$user->id] ?? 0),
                'service_ids' => $user->services
                    ->where('is_active', true)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            ]),
        ]);
    }

    private function roleLabel(User $user): string
    {
        $parts = [$user->role === UserRole::Owner ? 'Owner' : 'Staff'];

        if (! $user->is_active) {
            $parts[] = 'inactive';
        } elseif (! $user->is_bookable) {
            $parts[] = 'not bookable';
        }

        return implode(' · ', $parts);
    }

    /** @param  Collection<int, AvailabilityRule>  $rules */
    private function hoursSummary(Collection $rules): string
    {
        if ($rules->isEmpty()) {
            return 'No hours set';
        }

        $byDay = $rules
            ->groupBy(fn (AvailabilityRule $rule) => (int) ($rule->weekday instanceof Weekday ? $rule->weekday->value : $rule->weekday))
            ->map(fn (Collection $day) => $day
                ->map(fn (AvailabilityRule $rule) => substr((string) $rule->start_time, 0, 5).'–'.substr((string) $rule->end_time, 0, 5))
                ->implode(', '))
            ->sortKeys();

        $parts = [];
        $runStart = null;
        $runEnd = null;
        $runTimes = null;

        foreach ($byDay as $day => $times) {
            if ($runTimes === $times && $runEnd === $day - 1) {
                $runEnd = $day;

                continue;
            }

            if ($runTimes !== null) {
                $parts[] = $this->run($runStart, $runEnd, $runTimes);
            }

            $runStart = $runEnd = $day;
            $runTimes = $times;
        }

        if ($runTimes !== null) {
            $parts[] = $this->run($runStart, $runEnd, $runTimes);
        }

        $closed = array_values(array_diff(array_keys(self::DAY_NAMES), $byDay->keys()->all()));

        if ($closed !== [] && count($closed) <= 3) {
            $parts[] = 'closed '.implode(' & ', array_map(fn (int $day) => self::DAY_NAMES[$day], $closed));
        }

        return implode(' · ', $parts);
    }

    private function run(int $start, int $end, string $times): string
    {
        $days = $start === $end
            ? self::DAY_NAMES[$start]
            : self::DAY_NAMES[$start].'–'.self::DAY_NAMES[$end];

        return $days.' · '.$times;
    }

    /** @param  Collection<int, AvailabilityRule>  $rules */
    private function weeklyHours(Collection $rules): ?string
    {
        if ($rules->isEmpty()) {
            return null;
        }

        $minutes = $rules->sum(fn (AvailabilityRule $rule) => $this->ruleMinutes($rule));

        $hours = round($minutes / 60, 1);

        return (floor($hours) === $hours ? (string) (int) $hours : number_format($hours, 1)).' h';
    }

    /**
     * @param  Collection<int, AvailabilityRule>  $rules
     * @return list<float>
     */
    private function dailyHours(Collection $rules): array
    {
        $hours = array_fill(1, 7, 0.0);

        foreach ($rules as $rule) {
            $day = (int) ($rule->weekday instanceof Weekday ? $rule->weekday->value : $rule->weekday);
            $hours[$day] += $this->ruleMinutes($rule) / 60;
        }

        return array_values(array_map(fn (float $value) => round($value, 1), $hours));
    }

    private function ruleMinutes(AvailabilityRule $rule): int
    {
        $start = CarbonImmutable::createFromFormat('H:i:s', str_pad((string) $rule->start_time, 8, ':00'));
        $end = CarbonImmutable::createFromFormat('H:i:s', str_pad((string) $rule->end_time, 8, ':00'));

        return max(0, (int) $end->diffInMinutes($start, true));
    }

    /** @return array<int, int> */
    private function bookedThisWeek(string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);

        return Booking::query()
            ->where('starts_at', '>=', $now->startOfWeek()->utc())
            ->where('starts_at', '<', $now->startOfWeek()->addWeek()->utc())
            ->whereNotIn('status', BookingStatus::vacatingValues())
            ->selectRaw('staff_id, count(*) as aggregate')
            ->groupBy('staff_id')
            ->pluck('aggregate', 'staff_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $staff = User::query()->create([
            ...$request->safe()->except('service_ids'),
            'password' => Str::password(32),
            'role' => UserRole::Staff,
            'is_bookable' => $request->boolean('is_bookable', true),
            'is_active' => true,
            'can_see_customer_contacts' => $request->boolean('can_see_customer_contacts', true),
            'colour' => $request->input('colour', '#71717A'),
        ]);

        if ($request->exists('service_ids')) {
            StaffServices::syncActive($staff, $request->collect('service_ids'));
        } else {
            StaffServices::linkAllActive($staff);
        }

        return redirect()->route('staff.index')->with('toast', 'Staff saved.');
    }

    public function update(UpdateStaffRequest $request, User $staff): RedirectResponse
    {
        $staff->update(Arr::except($request->validated(), 'service_ids'));

        if ($request->exists('service_ids')) {
            StaffServices::syncActive($staff, $request->collect('service_ids'));
        }

        return redirect()->route('staff.index')->with('toast', 'Staff updated.');
    }
}
