<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\BookingPayload;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->withCount(['subjects', 'bookings'])
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'subjects_count' => $customer->subjects_count,
                'bookings_count' => $customer->bookings_count,
            ]);

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
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
