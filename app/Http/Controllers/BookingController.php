<?php

namespace App\Http\Controllers;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\Bookings\StoreManualBookingRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\BookingPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $status = $request->string('status')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $query = Booking::query()->with(['staff', 'service', 'customer', 'subject'])->orderByDesc('starts_at');

        if (in_array($status, array_column(BookingStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($from, $tenant->timezone)->startOfDay()->utc());
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $query->where('starts_at', '<', CarbonImmutable::parse($to, $tenant->timezone)->addDay()->startOfDay()->utc());
        }

        return Inertia::render('Bookings/Index', [
            'filters' => [
                'status' => $status,
                'from' => $from,
                'to' => $to,
            ],
            'bookings' => $query->limit(200)->get()->map(
                fn (Booking $booking) => BookingPayload::toArray($booking, $tenant->timezone)
            )->values(),
        ]);
    }

    public function show(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        return Inertia::render('Bookings/Show', [
            'booking' => BookingPayload::toArray(
                $booking->load(['staff', 'service', 'customer', 'subject']),
                $tenant->timezone,
            ),
            'waitlist_matches' => $this->waitlistPreview($booking),
        ]);
    }

    public function destroy(Booking $booking, Request $request, BookingService $bookings): RedirectResponse
    {
        $this->authorize('update', $booking);

        $offer = $request->boolean('offer_waitlist', true);
        $bookings->cancel($booking, $request->string('reason')->toString() ?: 'admin', $offer);

        return redirect()->route('bookings.index')->with('toast', 'Booking cancelled.');
    }

    /**
     * @return array{count: int}
     */
    private function waitlistPreview(Booking $booking): array
    {
        $matches = app(WaitlistOfferer::class)->rankedMatches(
            $booking->tenant,
            $booking->service,
            CarbonImmutable::parse($booking->starts_at)->utc(),
        );

        return ['count' => $matches->count()];
    }

    public function store(StoreManualBookingRequest $request, BookingService $bookings): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $service = Service::query()->findOrFail($request->integer('service_id'));
        $staff = User::query()->findOrFail($request->integer('staff_id'));
        $startsAt = CarbonImmutable::parse($request->string('starts_at')->toString(), $tenant->timezone)->utc();

        $customer = $request->filled('customer_id')
            ? Customer::query()->findOrFail($request->integer('customer_id'))
            : $this->createCustomer($request);

        $subject = $this->resolveSubject($customer, $request);

        try {
            $bookings->create(
                $tenant,
                $service,
                $staff,
                $customer,
                $startsAt,
                BookingSource::Manual,
                $subject,
                rebookIntervalDays: $request->filled('rebook_interval_days')
                    ? $request->integer('rebook_interval_days')
                    : null,
            );
        } catch (SlotUnavailableException $exception) {
            return back()->withErrors(['starts_at' => $exception->getMessage()]);
        }

        return redirect()->route('diary.index', [
            'date' => $startsAt->timezone($tenant->timezone)->toDateString(),
        ])->with('toast', 'Booking saved.');
    }

    private function createCustomer(StoreManualBookingRequest $request): Customer
    {
        $customer = new Customer;
        $customer->fill([
            'name' => $request->string('customer_name')->toString(),
            'email' => $request->filled('customer_email')
                ? $request->string('customer_email')->toString()
                : null,
            'phone' => $request->input('customer_phone'),
        ]);
        $customer->save();

        return $customer;
    }

    private function resolveSubject(Customer $customer, StoreManualBookingRequest $request): ?Subject
    {
        if ($request->filled('subject_id')) {
            return Subject::query()
                ->where('customer_id', $customer->id)
                ->findOrFail($request->integer('subject_id'));
        }

        if (! $request->filled('subject_name')) {
            return null;
        }

        $subject = new Subject;
        $subject->fill([
            'customer_id' => $customer->id,
            'name' => $request->string('subject_name')->toString(),
            'attributes' => $request->input('subject_attributes', []),
        ]);
        $subject->save();

        return $subject;
    }
}
