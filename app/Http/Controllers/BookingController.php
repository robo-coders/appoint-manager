<?php

namespace App\Http\Controllers;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\DepositStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageStatus;
use App\Exceptions\BookingNotCompletableException;
use App\Exceptions\RequestNotPendingException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\Bookings\StoreManualBookingRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Service;
use App\Models\Subject;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\BookingPayload;
use App\Support\MaskedContact;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookingController extends Controller
{
    private const PAGE_SIZE = 25;

    /**
     * The words a salon owner uses, once. `pending` is "awaiting deposit" on
     * every screen that shows it, and it is spelt out here rather than in each.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'pending' => 'Awaiting deposit',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
        'declined' => 'Declined',
        'completed' => 'Completed',
        'no_show' => 'No show',
    ];

    /** @var array<string, string> */
    private const MESSAGE_LABELS = [
        'booking_confirmed' => 'Confirmation',
        'booking_requested' => 'Request received',
        'booking_declined' => 'Decline',
        'reminder' => 'Reminder',
        'cancelled' => 'Cancellation',
        'rescheduled' => 'New time',
        'salon_new_booking' => 'New booking alert',
        'salon_new_request' => 'New request alert',
        'salon_cancellation' => 'Cancellation alert',
        'daily_agenda' => 'Daily agenda',
        'waitlist_offer' => 'Waitlist offer',
        'waitlist_gone' => 'Waitlist slot gone',
        'rebook_due' => 'Rebooking reminder',
    ];

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

        $query = $this->filtered($tenant->timezone, $from, $to);

        if (in_array($status, array_column(BookingStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
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
            'counts' => $this->statusCounts($tenant->timezone, $from, $to),
            'bookings' => $query
                ->with(['staff', 'service', 'customer', 'subject'])
                ->paginate(self::PAGE_SIZE)
                ->withQueryString()
                ->through(fn (Booking $booking) => BookingPayload::toArray($booking, $tenant->timezone)),
        ]);
    }

    /**
     * The list's date window, without the status filter.
     *
     * Both the page and its counts need it, and they have to agree: a tab
     * reading "Confirmed 34" over a table showing six rows is a tab that is
     * counting a different question from the one the table answered.
     *
     * @return Builder<Booking>
     */
    private function filtered(string $timezone, string $from, string $to): Builder
    {
        $query = Booking::query();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc());
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $query->where('starts_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc());
        }

        return $query;
    }

    /**
     * What each status filter would show, so the tabs can say it before she
     * presses one.
     *
     * `total` is every status in the window rather than the sum of the four
     * tabs: declined, completed and no-show rows are real bookings and are on
     * the All tab, they simply have no tab of their own.
     *
     * @return array<string, int>
     */
    private function statusCounts(string $timezone, string $from, string $to): array
    {
        $counts = $this->filtered($timezone, $from, $to)
            ->getQuery()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            ...array_reduce(
                BookingStatus::cases(),
                fn (array $carry, BookingStatus $status) => $carry + [$status->value => (int) ($counts[$status->value] ?? 0)],
                [],
            ),
        ];
    }

    /**
     * The list as a spreadsheet.
     *
     * Same filters, same order, no pagination — an export that only covered the
     * page she happened to be on would be a trap rather than a feature. Streamed
     * rather than built in memory: a busy salon's whole history is a bigger
     * array than a request should hold.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Booking::class);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $status = $request->string('status')->toString();
        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        if (! isset(self::SORTS[$sort])) {
            $sort = 'when';
            $direction = $request->filled('direction') ? $direction : 'desc';
        }

        $query = $this->filtered(
            $tenant->timezone,
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        );

        if (in_array($status, array_column(BookingStatus::cases(), 'value'), true)) {
            $query->where('status', $status);
        }

        $this->applySort($query, $sort, $direction);

        $filename = 'bookings-'.CarbonImmutable::now($tenant->timezone)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $tenant) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['When', 'Customer', 'Subject', 'Service', 'Staff', 'Status', 'Deposit', 'Amount', 'Source']);

            $query->with(['staff', 'service', 'customer', 'subject'])->chunk(200, function ($bookings) use ($handle, $tenant) {
                foreach ($bookings as $booking) {
                    fputcsv($handle, [
                        $booking->starts_at?->timezone($tenant->timezone)->format('Y-m-d H:i'),
                        $booking->customer?->name,
                        $booking->subject?->name,
                        $booking->service?->name,
                        $booking->staff?->name,
                        $booking->status->value,
                        $booking->deposit_status->value,
                        $booking->price_at_booking->formatted(),
                        $booking->source->value,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
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

        /*
         * A tiebreaker, so the list has one order rather than whichever order
         * the engine happens to return.
         *
         * Every sort here has ties by construction: two appointments at 09:00,
         * two customers called Oyelaran, four bookings all `confirmed`. Without
         * a final key, MySQL is free to order tied rows differently between
         * identical queries — so pagination could show a row twice and skip
         * another, and the 768px snapshot of this table failed with two pairs of
         * same-minute rows swapped.
         */
        $query->orderBy('bookings.id', 'desc');
    }

    public function show(Booking $booking): Response
    {
        $this->authorize('view', $booking);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $booking->load(['staff', 'service', 'customer', 'subject']);

        return Inertia::render('Bookings/Show', [
            'booking' => BookingPayload::toArray($booking, $tenant->timezone),
            'groups' => $this->detailGroups($booking, $tenant->timezone),
            'activity' => $this->activity($booking, $tenant->timezone),
            'waitlist_matches' => $this->waitlistPreview($booking),
        ]);
    }

    /**
     * The record, grouped.
     *
     * It was a flat column of eight sentences — status, deposit, total, who
     * booked it — with nothing saying which of them belonged together, so
     * answering "has this been paid" meant reading all eight. Four headings,
     * label on the left and value on the right, and the question is answered by
     * looking in one place.
     *
     * Every row is a column that exists. There is no "reminder scheduled" here
     * and no auto-release countdown, because this product does not store either
     * as a fact about a booking — the reminder is a queued job and the release
     * is a scheduled sweep. A field on a record page that is computed from a
     * guess is worse than a field that is not there.
     *
     * `mono` marks the values that are numbers, dates or identifiers, which is
     * the same rule the rest of the product follows.
     *
     * @return list<array<string, mixed>>
     */
    private function detailGroups(Booking $booking, string $timezone): array
    {
        $customer = $booking->customer;

        /*
         * Whether the two contact rows carry a value or a mask. Resolved here
         * and not in the Vue: a `v-if` would still have put the number in the
         * Inertia payload, which is a page of JSON anybody can read in devtools.
         */
        $showContact = request()->user()?->can('viewContact', $booking) ?? false;
        $starts = $booking->starts_at?->timezone($timezone);
        $ends = $booking->ends_at?->timezone($timezone);

        $history = $customer === null
            ? null
            : Booking::query()
                ->where('customer_id', $customer->id)
                ->selectRaw('count(*) as total')
                ->selectRaw('sum(case when status = ? then 1 else 0 end) as missed', [BookingStatus::NoShow->value])
                ->first();

        $price = $booking->price_at_booking;
        $deposit = $booking->deposit_at_booking;
        $paid = $booking->deposit_status === DepositStatus::Paid ? $deposit->amount : 0;

        $row = fn (string $key, ?string $value, bool $mono = false) => [
            'key' => $key,
            'value' => $value ?? '—',
            'mono' => $mono,
        ];

        $groups = [
            [
                'label' => 'Customer',
                'rows' => array_values(array_filter([
                    $row('Name', $customer?->name),
                    $booking->subject_id === null ? null : $row('Pet', $booking->subject?->name),
                    $showContact
                        ? $row('Email', $customer?->email, true)
                        : $row('Email', MaskedContact::hasEmail($customer?->email) ? MaskedContact::NOTICE : null),
                    $showContact
                        ? $row('Phone', $customer?->phone, true)
                        : $row('Phone', MaskedContact::phone($customer?->phone), true),
                    $history === null ? null : $row(
                        'History',
                        (int) $history->total === 1
                            ? 'First booking'
                            : (int) $history->total.' bookings · '.(int) $history->missed.' no-show'.((int) $history->missed === 1 ? '' : 's'),
                    ),
                ])),
            ],
            [
                'label' => 'Scheduling',
                'rows' => [
                    $row('Date', $starts?->format('l j F Y')),
                    $row('Time', $starts === null || $ends === null ? null : $starts->format('H:i').' — '.$ends->format('H:i'), true),
                    $row('Duration', $booking->service?->duration_minutes === null ? null : $booking->service->duration_minutes.' min', true),
                    $row('Staff', $booking->staff?->name),
                    $row('Booked', $booking->created_at?->timezone($timezone)->format('j M Y, H:i'), true),
                ],
            ],
            [
                'label' => 'Payment',
                /*
                 * A salon that takes no deposits gets three rows of £0.00 and a
                 * balance identical to the price, which is four lines to say one
                 * thing. Deposits are opt-in and most salons never turn them on,
                 * so the rows exist only where there is a deposit to talk about.
                 */
                'rows' => array_values(array_filter([
                    $row('Service price', $price->formatted(), true),
                    $deposit->amount === 0 ? null : $row('Deposit due', $deposit->formatted(), true),
                    $deposit->amount === 0 ? null : $row('Deposit paid', (new Money($paid, $price->currency))->formatted(), true),
                    $row(
                        $deposit->amount === 0 ? 'Due on the day' : 'Balance on the day',
                        (new Money(max(0, $price->amount - $paid), $price->currency))->formatted(),
                        true,
                    ),
                    $booking->is_loyalty_reward ? $row('Loyalty', 'Free groom — reward redeemed') : null,
                ])),
            ],
            [
                'label' => 'Status',
                'rows' => array_values(array_filter([
                    $row('Booking', self::STATUS_LABELS[$booking->status->value] ?? $booking->status->value),
                    $deposit->amount === 0 ? null : $row('Deposit', $booking->deposit_status->label()),
                    $row('Source', $booking->source === BookingSource::Manual ? 'Added in the diary' : 'Booking page'),
                    $booking->request_expires_at === null
                        ? null
                        : $row('Request expires', $booking->request_expires_at->timezone($timezone)->format('j M, H:i'), true),
                    $booking->cancelled_at === null
                        ? null
                        : $row('Cancelled', $booking->cancelled_at->timezone($timezone)->format('j M Y, H:i'), true),
                    $booking->cancellation_reason === null ? null : $row('Reason', $booking->cancellation_reason),
                ])),
            ],
        ];

        return $groups;
    }

    /**
     * What has actually been sent about this appointment.
     *
     * `messages` is the send log the product already keeps, so this is a read of
     * something true rather than a timeline invented for the page. A booking
     * nobody has been written to about shows nothing, which is the honest state.
     *
     * @return list<array<string, string>>
     */
    private function activity(Booking $booking, string $timezone): array
    {
        return Message::query()
            ->where('booking_id', $booking->id)
            ->orderBy('created_at')
            ->get(['id', 'channel', 'type', 'status', 'created_at'])
            ->map(fn (Message $message) => [
                'at' => $message->created_at?->timezone($timezone)->format('j M, H:i') ?? '',
                'text' => self::MESSAGE_LABELS[$message->type->value].' '
                    .($message->channel === MessageChannel::Sms ? 'texted' : 'emailed')
                    .($message->status === MessageStatus::Failed ? ' — failed to send' : '')
                    .'.',
            ])
            ->values()
            ->all();
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
