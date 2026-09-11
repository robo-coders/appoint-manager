<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customers\UpdateCustomerNotesRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LoyaltyEnrolment;
use App\Models\Subject;
use App\Services\CustomerHistoryService;
use App\Services\Loyalty\Loyalty;
use App\Support\BookingPayload;
use App\Support\ContactVisibility;
use App\Support\MaskedContact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
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

        $contacts = ContactVisibility::for($request->user());

        $query = Customer::query()->withCount(['subjects', 'bookings']);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function ($inner) use ($like, $contacts) {
                $inner->where('name', 'like', $like);

                if ($contacts->unrestricted()) {
                    $inner->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                }
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
                ->through(function (Customer $customer) use ($contacts) {
                    $visible = $contacts->customer($customer);

                    return [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $visible ? $customer->email : null,
                        'phone' => $visible ? $customer->phone : MaskedContact::phone($customer->phone),
                        'has_email' => MaskedContact::hasEmail($customer->email),
                        'contact_hidden' => ! $visible,
                        'subjects_count' => $customer->subjects_count,
                        'bookings_count' => $customer->bookings_count,
                    ];
                }),
        ]);
    }

    public function show(Customer $customer, Request $request): Response
    {
        $this->authorize('view', $customer);

        $tenant = current_tenant();
        abort_unless($tenant !== null, 403);

        $customer->load(['subjects', 'notesEditor']);

        $bookings = $customer->bookings()
            ->with(['staff', 'service', 'subject'])
            ->orderBy('starts_at')
            ->get();

        $history = app(CustomerHistoryService::class);
        $visible = $request->user()?->can('viewContact', $customer) ?? false;

        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $visible ? $customer->email : null,
                'phone' => $visible ? $customer->phone : MaskedContact::phone($customer->phone),
                'has_email' => MaskedContact::hasEmail($customer->email),
                'contact_hidden' => ! $visible,
                'requires_full_payment' => (bool) $customer->requires_full_payment_override,
                'subjects' => $customer->subjects->map(fn (Subject $subject) => [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'descriptor' => $this->subjectDescriptor($subject),
                ])->values(),
            ],
            'badge' => $history->badge($bookings),
            'stats' => $history->stats($bookings, $tenant->timezone),
            'attendance' => $history->attendance($bookings, $tenant->timezone),
            'cadence' => $history->cadence($bookings, $tenant->timezone),
            'suggestedRule' => $history->suggestedRule($customer, $bookings),
            'notes' => [
                'text' => $customer->notes,
                'editor_name' => $customer->notesEditor?->name,
                'updated_at' => $customer->notes_updated_at?->timezone($tenant->timezone)->format('j M Y'),
            ],
            'ledger' => $this->ledger($customer, $tenant->timezone, $request),
            'ledgerExpanded' => $request->boolean('visits_all'),
            'loyalty' => $this->loyaltyPanel($customer),
        ]);
    }

    public function updateNotes(UpdateCustomerNotesRequest $request, Customer $customer): RedirectResponse
    {
        $notes = $request->string('notes')->trim()->toString();

        $customer->forceFill([
            'notes' => $notes === '' ? null : $notes,
            'notes_updated_at' => now(),
            'notes_updated_by' => $request->user()?->id,
        ])->save();

        return back(303)->with('toast', 'Note saved.');
    }

    public function requireFullPayment(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $customer->forceFill(['requires_full_payment_override' => true])->save();

        return back(303)->with('toast', 'This customer now pays in full up front.');
    }

    public function dismissSuggestedRule(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $customer->forceFill(['suggested_rule_dismissed_at' => now()])->save();

        return back(303);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    private function ledger(Customer $customer, string $timezone, Request $request): LengthAwarePaginator
    {
        $history = app(CustomerHistoryService::class);

        $size = $request->boolean('visits_all')
            ? (int) config('customers.ledger_expanded_page_size')
            : (int) config('customers.ledger_page_size');

        return $customer->bookings()
            ->with(['staff', 'service', 'subject'])
            ->orderByDesc('starts_at')
            ->paginate($size, ['*'], 'visits')
            ->withQueryString()
            ->through(fn (Booking $booking) => BookingPayload::toArray($booking, $timezone, [
                'outcome' => $history->outcome($booking),
                'paid' => $history->amountPaid($booking)->toArray(),
            ]));
    }

    private function subjectDescriptor(Subject $subject): ?string
    {
        $fields = current_tenant()?->vertical()['subject_fields'] ?? [];
        $attributes = $subject->attributes ?? [];
        $parts = [];

        foreach ($fields as $field) {
            if (($field['type'] ?? 'text') === 'textarea') {
                continue;
            }

            $value = trim((string) ($attributes[$field['key']] ?? ''));

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** @return array<string, mixed>|null */
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
            'earning' => $enrolment->isEarning(),
            'cycles_completed' => $enrolment->cycles_completed,
            'free_sessions' => $free,
        ];
    }
}
