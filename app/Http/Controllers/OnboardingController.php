<?php

namespace App\Http\Controllers;

use App\Enums\BookingSource;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\Onboarding\CompleteOnboardingRequest;
use App\Http\Requests\Onboarding\UpdateBasicsRequest;
use App\Http\Requests\Onboarding\UpdateBusinessDetailsRequest;
use App\Http\Requests\Onboarding\UpdateServicesRequest;
use App\Http\Requests\Onboarding\UpdateStaffRequest;
use App\Models\AvailabilityRule;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Booking\BookingService;
use App\Support\SetupSteps;
use App\Support\StaffServices;
use App\Support\TenantSlug;
use App\Support\Timezones;
use App\Support\VerticalInterval;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

        if (! in_array($step, $completed, true)) {
            $step = $this->firstIncompleteStep($completed);
        }

        return Inertia::render('Onboarding/Index', [
            'step' => $step,
            'completedSteps' => array_values(array_unique(['account', ...$completed])),
            'steps' => SetupSteps::all(),
            'onboardingSteps' => SetupSteps::ONBOARDING,
            'timezones' => Timezones::identifiers(),

            'basics' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'type' => $tenant->type,
                'hours' => $this->weekFor($owner, $rules, in_array('basics', $completed, true)),
            ],
            'verticals' => Vertical::query()
                ->orderBy('label')
                ->get()
                ->map(fn (Vertical $vertical) => [
                    'value' => $vertical->key,
                    'label' => $vertical->label,
                    'note' => $vertical->note(),
                ])
                ->values()
                ->all(),

            'business' => [
                'timezone' => $tenant->timezone,
                'phone' => $tenant->phone,
                'address_line_1' => $tenant->address_line_1,
                'address_line_2' => $tenant->address_line_2,
                'city' => $tenant->city,
                'postcode' => $tenant->postcode,
                'booking_mode' => $tenant->booking_mode->value,
                'request_requires_deposit' => $tenant->request_requires_deposit,
            ],

            'service' => $this->serviceFor($services, in_array('services', $completed, true)),

            'staff' => $staff->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_owner' => $user->isOwner(),
            ])->all(),

            'bookingUrl' => $tenant->publicBookingUrl(),
            'firstBookingDefault' => CarbonImmutable::now($tenant->timezone)
                ->addDay()
                ->setTime(9, 0)
                ->format('Y-m-d\\TH:i'),
        ]);
    }

    public function checkSlug(Request $request): JsonResponse
    {
        $slug = Str::slug((string) $request->string('slug'));

        if ($slug === '' || strlen($slug) < 3) {
            return response()->json(['slug' => $slug, 'available' => false, 'suggestion' => null]);
        }

        $taken = Tenant::withTrashed()
            ->where('slug', $slug)
            ->whereKeyNot(current_tenant_id())
            ->exists();

        return response()->json([
            'slug' => $slug,
            'available' => ! $taken,
            'suggestion' => $taken ? TenantSlug::generate($slug) : null,
        ]);
    }

    public function updateBasics(UpdateBasicsRequest $request): RedirectResponse
    {
        $tenant = current_tenant();
        $owner = $this->owner($request);

        DB::transaction(function () use ($request, $tenant, $owner): void {
            $tenant->update([
                'name' => $request->validated('name'),
                'slug' => $request->validated('slug'),
                'type' => $request->validated('type'),
            ]);

            AvailabilityRule::query()->where('user_id', $owner->id)->delete();

            foreach ($request->openDays() as $day) {
                AvailabilityRule::query()->create([
                    'user_id' => $owner->id,
                    'weekday' => Weekday::from($day['weekday']),
                    'start_time' => $day['start_time'].':00',
                    'end_time' => $day['end_time'].':00',
                ]);
            }
        });

        $tenant->markOnboardingStep('basics');

        return redirect()->route('onboarding.show', ['step' => 'business']);
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
        $payload = [
            'name' => $request->validated('name'),
            'duration_minutes' => $request->validated('duration_minutes'),
            'price' => $request->validated('price'),
            'deposit_amount' => $request->validated('deposit_amount'),
            'sort_order' => 0,
            'is_active' => true,
        ];

        $id = $request->validated('id');
        $service = $id === null ? null : Service::query()->find($id);

        if ($service !== null) {
            $service->update($payload);
        } else {
            $payload['suggested_interval_days'] = VerticalInterval::daysForNamedService(
                (string) current_tenant()?->type,
                $payload['name'],
            );

            Service::query()->create($payload);
        }

        StaffServices::linkAllActive($this->owner($request));

        current_tenant()?->markOnboardingStep('services');

        return redirect()->route('onboarding.show', ['step' => 'staff']);
    }

    public function updateStaff(UpdateStaffRequest $request): RedirectResponse
    {
        $member = $request->validated('staff');

        if ($member !== null) {
            $created = User::query()->create([
                'name' => $member['name'],
                'email' => $member['email'],
                'password' => Str::password(32),
                'role' => UserRole::Staff,
                'is_bookable' => true,
                'is_active' => true,
                'can_see_customer_contacts' => (bool) ($member['can_see_customer_contacts'] ?? true),
                'colour' => '#0F766E',
            ]);

            StaffServices::linkAllActive($created);
        }

        current_tenant()?->markOnboardingStep('staff');

        return redirect()->route('onboarding.show', ['step' => 'link']);
    }

    public function complete(CompleteOnboardingRequest $request): RedirectResponse
    {
        $tenant = current_tenant();

        $collision = DB::transaction(function () use ($request, $tenant): bool {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();

            $taken = Tenant::withTrashed()
                ->where('slug', $request->validated('slug'))
                ->whereKeyNot($tenant->getKey())
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                return true;
            }

            $locked->update([
                'slug' => $request->validated('slug'),
                'booking_page_live' => true,
            ]);

            return false;
        });

        if ($collision) {
            throw ValidationException::withMessages([
                'slug' => 'Someone claimed that address while you were setting up. Pick another one on step one.',
            ]);
        }

        $first = $request->validated('first_booking');
        $booking = null;

        if ($first !== null) {
            try {
                $booking = $this->createFirstBooking($tenant, $first);
            } catch (SlotUnavailableException $exception) {
                return back()->withErrors(['first_booking' => $exception->getMessage()]);
            }
        }

        $tenant->markOnboardingStep(SetupSteps::FINAL);

        if ($booking !== null) {
            return redirect()->route('diary.index', [
                'date' => $booking->starts_at->timezone($tenant->timezone)->toDateString(),
            ])->with('toast', 'You’re open. Here is your diary, with your first appointment in it.');
        }

        return redirect()->route('diary.index')->with('toast', 'You’re open. This is your diary.');
    }

    /** @param  array{customer_name: string, customer_email?: string|null, service_id: int, staff_id: int, starts_at: string}  $first */
    private function createFirstBooking(Tenant $tenant, array $first)
    {
        $email = filled($first['customer_email'] ?? null) ? $first['customer_email'] : null;

        $customer = $email === null
            ? new Customer
            : Customer::query()->firstOrNew(['email' => $email]);
        $customer->fill(['name' => $first['customer_name'], 'email' => $email]);
        $customer->save();

        return app(BookingService::class)->create(
            $tenant,
            Service::query()->findOrFail($first['service_id']),
            User::query()->findOrFail($first['staff_id']),
            $customer,
            CarbonImmutable::parse($first['starts_at'], $tenant->timezone)->utc(),
            BookingSource::Manual,
        );
    }

    private function owner(Request $request): User
    {
        return User::query()->where('role', UserRole::Owner)->first() ?? $request->user();
    }

    /** @param  list<string>  $completed */
    private function firstIncompleteStep(array $completed): string
    {
        foreach (SetupSteps::ONBOARDING as $step) {
            if (! in_array($step, $completed, true)) {
                return $step;
            }
        }

        return SetupSteps::FINAL;
    }

    /**
     * @param  Collection<int, AvailabilityRule>  $rules
     * @return list<array{weekday: int, open: bool, start_time: string, end_time: string}>
     */
    private function weekFor(User $owner, $rules, bool $saved): array
    {
        $mine = $rules->where('user_id', $owner->id);
        $week = [];

        foreach (Weekday::cases() as $weekday) {
            $day = $mine->where('weekday', $weekday);

            if ($day->isNotEmpty()) {
                $week[] = [
                    'weekday' => $weekday->value,
                    'open' => true,
                    'start_time' => substr((string) $day->min('start_time'), 0, 5),
                    'end_time' => substr((string) $day->max('end_time'), 0, 5),
                ];

                continue;
            }

            $weekend = in_array($weekday, [Weekday::Saturday, Weekday::Sunday], true);

            $week[] = [
                'weekday' => $weekday->value,
                'open' => $saved ? false : ! $weekend,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ];
        }

        return $week;
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array{id: int|null, name: string, duration_minutes: int, price: int, deposit_amount: int}
     */
    private function serviceFor($services, bool $saved): array
    {
        $existing = $services->first();

        if ($existing !== null) {
            return [
                'id' => $existing->id,
                'name' => $existing->name,
                'duration_minutes' => $existing->duration_minutes,
                'price' => $existing->price->amount,
                'deposit_amount' => $existing->deposit_amount->amount,
            ];
        }

        $default = $saved ? null : (current_tenant()?->vertical()['default_services'][0] ?? null);

        return [
            'id' => null,
            'name' => $default['name'] ?? '',
            'duration_minutes' => (int) ($default['duration_minutes'] ?? 60),
            'price' => (int) ($default['price'] ?? 0),
            'deposit_amount' => (int) ($default['deposit_amount'] ?? 0),
        ];
    }
}
