<?php

namespace App\Http\Controllers;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\BookingNotCompletableException;
use App\Exceptions\RequestNotPendingException;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    private const PAGE_SIZE = 25;

    /** @var array<string, string> */
    private const SORTS = [
        'when' => 'starts_at',
        'customer' => 'customer',
        'service' => 'service',
        'staff' => 'staff',
        'status' => 'status',
        'amount' => 'price_at_booking',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Booking::class);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $status = $request->string('status')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        if (! isset(self::SORTS[$sort])) {
            $sort = 'when';
            $direction = $request->filled('direction') ? $direction : 'desc';
        }

        $query = Booking::query()->with(['staff', 'service', 'customer', 'subject']);

        if (in_array($status, array_column(BookingStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($from, $tenant->timezone)->startOfDay()->utc());
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $query->where('starts_at', '<', CarbonImmutable::parse($to, $tenant->timezone)->addDay()->startOfDay()->utc());
        }

        $this->applySort($query, $sort, $direction);

        return Inertia::render('Bookings/Index', [
            'filters' => [
                'status' => $status,
                'from' => $from,
                'to' => $to,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'bookings' => $query
                ->paginate(self::PAGE_SIZE)
                ->withQueryString()
                ->through(fn (Booking $booking) => BookingPayload::toArray($booking, $tenant->timezone)),
        ]);
    }

    /**
     * @param  Builder<Booking>  $query
     */
    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            'customer' => $query->orderBy(
                Customer::query()->select('name')->whereColumn('customers.id', 'bookings.customer_id'),
                $direction,
            ),
            'service' => $query->orderBy(
                Service::query()->select('name')->whereColumn('services.id', 'bookings.service_id'),
                $direction,
            ),
            'staff' => $query->orderBy(
                User::query()->select('name')->whereColumn('users.id', 'bookings.staff_id'),
                $direction,
            ),
            default => $query->orderBy(self::SORTS[$sort], $direction),
        };
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

    public function approve(Booking $booking, BookingService $bookings): RedirectResponse
    {
        $this->authorize('update', $booking);

        try {
            $bookings->approve($booking, request()->user());
        } catch (RequestNotPendingException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('toast', 'Request confirmed.');
    }

    public function decline(Booking $booking, Request $request, BookingService $bookings): RedirectResponse
    {
        $this->authorize('update', $booking);

        $reason = $request->string('reason')->toString() ?: null;

        try {
            $bookings->decline($booking, $reason, $request->user());
        } catch (RequestNotPendingException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('toast', 'Request declined.');
    }

    /**
     * Mark an appointment as having happened.
     *
     * The loyalty stamp is not applied here — it hangs off `Booking`'s `updated`
     * hook, so this route and an import and a support script all agree. See
     * `BookingService::complete`.
     */
    public function complete(Booking $booking, Request $request, BookingService $bookings): RedirectResponse
    {
        $this->authorize('update', $booking);

        try {
            $bookings->complete($booking, $request->user());
        } catch (BookingNotCompletableException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        return back()->with('toast', 'Marked as done.');
    }

    /**
     * Mark an appointment as missed.
     *
     * The only writer of `BookingStatus::NoShow` in the app — the dashboard's
     * no-show rate read a status nothing could set. See
     * `BookingService::markNoShow`.
     */
    public function noShow(Booking $booking, Request $request, BookingService $bookings): RedirectResponse
    {
        $this->authorize('update', $booking);

        try {
            $bookings->markNoShow($booking, $request->user());
        } catch (BookingNotCompletableException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }

        return back()->with('toast', 'Marked as a no show.');
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
            $booking = $bookings->create(
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
        ])->with('toast', 'Booking saved.')->with('created_booking', [
            'correlation_id' => $request->input('correlation_id'),
            'booking' => BookingPayload::toArray($booking, $tenant->timezone),
        ]);
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
