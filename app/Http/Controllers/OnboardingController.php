<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Http\Requests\Onboarding\UpdateBusinessDetailsRequest;
use App\Http\Requests\Onboarding\UpdateOpeningHoursRequest;
use App\Http\Requests\Onboarding\UpdateServicesRequest;
use App\Http\Requests\Onboarding\UpdateStaffRequest;
use App\Models\AvailabilityRule;
use App\Models\Service;
use App\Models\User;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $tenant = current_tenant();

        if ($tenant === null) {
            abort(403);
        }

        if ($tenant->hasCompletedOnboarding()) {
            return redirect()->route('diary.index');
        }

        $services = Service::query()->orderBy('sort_order')->orderBy('name')->get();
        $staff = User::query()->orderBy('name')->get();
        $owner = $staff->firstWhere('role', UserRole::Owner) ?? $request->user();
        $rules = AvailabilityRule::query()->orderBy('weekday')->orderBy('start_time')->get();

        $completed = $tenant->onboardingCompletedSteps();
        $step = $request->string('step')->toString();
        $allowed = ['business', 'services', 'staff', 'hours'];

        if (! in_array($step, $allowed, true)) {
            $step = $this->firstIncompleteStep($completed);
        }

        $serviceRows = $services->isEmpty() && ! in_array('services', $completed, true)
            ? collect(current_tenant()?->vertical()['default_services'] ?? [])->map(fn (array $service, int $index) => [
                'id' => null,
                'name' => $service['name'],
                'duration_minutes' => $service['duration_minutes'],
                'price' => $service['price'],
                'deposit_amount' => $service['deposit_amount'],
                'sort_order' => $index,
            ])->all()
            : $services->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
                'price' => $service->price->amount,
                'deposit_amount' => $service->deposit_amount->amount,
                'sort_order' => $service->sort_order,
            ])->all();

        $hours = $rules->isEmpty() && ! in_array('hours', $completed, true)
            ? $this->defaultOwnerHours($owner)
            : $rules->map(fn (AvailabilityRule $rule) => [
                'user_id' => $rule->user_id,
                'weekday' => $rule->weekday->value,
                'start_time' => substr((string) $rule->start_time, 0, 5),
                'end_time' => substr((string) $rule->end_time, 0, 5),
            ])->all();

        return Inertia::render('Onboarding/Index', [
            'step' => $step,
            'completedSteps' => $completed,
            'timezones' => Timezones::identifiers(),
            'business' => [
                'timezone' => $tenant->timezone,
                'phone' => $tenant->phone,
                'address_line_1' => $tenant->address_line_1,
                'address_line_2' => $tenant->address_line_2,
                'city' => $tenant->city,
                'postcode' => $tenant->postcode,
            ],
            'services' => $serviceRows,
            'staff' => $staff->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_owner' => $user->isOwner(),
            ])->all(),
            'hours' => $hours,
        ]);
    }

    public function updateBusiness(UpdateBusinessDetailsRequest $request): RedirectResponse
    {
        $tenant = current_tenant();
        $tenant->update($request->validated());
        $tenant->markOnboardingStep('business');

        return redirect()->route('onboarding.show', ['step' => 'services']);
    }

    public function updateServices(UpdateServicesRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $keepIds = [];

            foreach (array_values($request->validated('services')) as $index => $row) {
                $service = isset($row['id'])
                    ? Service::query()->find($row['id'])
                    : null;

                $payload = [
                    'name' => $row['name'],
                    'duration_minutes' => $row['duration_minutes'],
                    'price' => $row['price'],
                    'deposit_amount' => $row['deposit_amount'],
                    'sort_order' => $index,
                    'is_active' => true,
                ];

                if ($service) {
                    $service->update($payload);
                } else {
                    $service = Service::query()->create($payload);
                }

                $keepIds[] = $service->id;
            }

            Service::query()->whereNotIn('id', $keepIds)->get()->each->delete();
        });

        current_tenant()?->markOnboardingStep('services');

        return redirect()->route('onboarding.show', ['step' => 'staff']);
    }

    public function updateStaff(UpdateStaffRequest $request): RedirectResponse
    {
        $existingEmails = User::query()->pluck('email')->all();

        foreach ($request->validated('staff') as $row) {
            if (in_array($row['email'], $existingEmails, true)) {
                continue;
            }

            User::query()->create([
                'name' => $row['name'],
                'email' => $row['email'],
                'password' => Str::password(32),
                'role' => UserRole::Staff,
                'is_bookable' => true,
                'is_active' => true,
                'colour' => '#0F766E',
            ]);
        }

        current_tenant()?->markOnboardingStep('staff');

        return redirect()->route('onboarding.show', ['step' => 'hours']);
    }

    public function updateHours(UpdateOpeningHoursRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            AvailabilityRule::query()->delete();

            foreach ($request->validated('rules') as $row) {
                AvailabilityRule::query()->create([
                    'user_id' => $row['user_id'],
                    'weekday' => Weekday::from((int) $row['weekday']),
                    'start_time' => $row['start_time'].':00',
                    'end_time' => $row['end_time'].':00',
                ]);
            }
        });

        current_tenant()?->markOnboardingStep('hours');

        return redirect()->route('diary.index')->with('toast', 'You’re set up. This is your diary.');
    }

    /**
     * @param  list<string>  $completed
     */
    private function firstIncompleteStep(array $completed): string
    {
        foreach (['business', 'services', 'staff', 'hours'] as $step) {
            if (! in_array($step, $completed, true)) {
                return $step;
            }
        }

        return 'hours';
    }

    /**
     * @return list<array{user_id: int, weekday: int, start_time: string, end_time: string}>
     */
    private function defaultOwnerHours(User $owner): array
    {
        $hours = [];

        foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $weekday) {
            $hours[] = [
                'user_id' => $owner->id,
                'weekday' => $weekday->value,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ];
        }

        return $hours;
    }
}
