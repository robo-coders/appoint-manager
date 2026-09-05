<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Services\Loyalty\Loyalty;
use App\Support\BookingPayload;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    private const PAGE_SIZE = 25;

    /** @var list<string> */
    private const SORTS = ['name', 'subjects_count', 'bookings_count'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';

        if (! in_array($sort, self::SORTS, true)) {
            $sort = 'name';
            $direction = $request->filled('direction') ? $direction : 'asc';
        }

        $query = Customer::query()->withCount(['subjects', 'bookings']);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        $query->orderBy($sort, $direction);

        return Inertia::render('Customers/Index', [
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'customers' => $query
                ->paginate(self::PAGE_SIZE)
                ->withQueryString()
                ->through(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'subjects_count' => $customer->subjects_count,
                    'bookings_count' => $customer->bookings_count,
                ]),
        ]);
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $customer->load(['subjects', 'bookings.staff', 'bookings.service', 'bookings.subject']);

        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'notes' => $customer->notes,
                'subjects' => $customer->subjects->map(fn ($subject) => [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'attributes' => $subject->attributes ?? [],
                ])->values(),
                'bookings' => $customer->bookings
                    ->sortByDesc('starts_at')
                    ->values()
                    ->map(fn ($booking) => BookingPayload::toArray($booking, $tenant->timezone)),
            ],
            'loyalty' => $this->loyaltyPanel($customer),
        ]);
    }

    /**
     * The customer's loyalty card, or null.
     *
     * Null when the tenant has the feature off, and null when it is on but this
     * customer has never booked since — in both cases the screen renders no
     * section at all rather than an empty one. There is no customer portal, so
     * this panel and the confirmation text are the only two places the count is
     * ever visible; between them they are the whole of the feature's visibility.
     *
     * `free_sessions` is the history: the appointments that were actually paid
     * for with stamps, newest first, which is a more useful record than
     * `cycles_completed` on its own because it says *when*.
     *
     * @return array<string, mixed>|null
     */
    private function loyaltyPanel(Customer $customer): ?array
    {
        $tenant = current_tenant();
        $loyalty = app(Loyalty::class);

        if ($tenant === null || ! $loyalty->enabled($tenant)) {
            return null;
        }

        $enrolment = LoyaltyEnrolment::query()
            ->with('package')
            ->where('customer_id', $customer->id)
            ->first();

        if ($enrolment === null) {
            return null;
        }

        $free = Booking::query()
            ->where('customer_id', $customer->id)
            ->where('is_loyalty_reward', true)
            ->with('service')
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'service_name' => $booking->service?->name,
                'starts_at_local' => $booking->starts_at?->timezone($tenant->timezone)->format('Y-m-d H:i'),
                'status' => $booking->status->value,
            ])
            ->all();

        return [
            'package_name' => $enrolment->package?->name,
            'reward' => $enrolment->package?->reward,
            'sessions_required' => $enrolment->package?->sessions_required,
            'stamps_used' => $enrolment->stamps_used,
            'remaining' => $enrolment->remaining(),
            'reward_due' => $enrolment->rewardDue(),
            // False when the package has been switched off or deleted under
            // them. The screen says so rather than showing a card that looks
            // live and is not.
            'earning' => $enrolment->isEarning(),
            'cycles_completed' => $enrolment->cycles_completed,
            'free_sessions' => $free,
        ];
    }
}
