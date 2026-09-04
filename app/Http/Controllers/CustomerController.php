<?php

namespace App\Http\Controllers;

use App\Models\Customer;
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
        ]);
    }
}
