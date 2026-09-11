<?php

namespace App\Http\Controllers;

use App\Enums\PreferredTime;
use App\Exceptions\CustomerRecordUnavailableException;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\Booking\CustomerResolver;
use App\Services\Booking\FreedSlots;
use App\Services\Waitlist\WaitlistOfferer;
use App\Support\ContactVisibility;
use App\Support\MaskedContact;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class WaitlistController extends Controller
{
    public function index(Request $request, FreedSlots $freed): Response
    {
        $this->authorize('viewAny', WaitlistEntry::class);
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $contacts = ContactVisibility::for($request->user());

        $entries = WaitlistEntry::query()
            ->with(['customer', 'service', 'subject'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (WaitlistEntry $entry) => [
                'id' => $entry->id,
                'customer_id' => $entry->customer_id,
                'customer_name' => $entry->customer?->name,
                /*
                 * The number is the point of this screen — it exists so
                 * somebody can ring down the list — so a staff member who
                 * cannot read it is told that, rather than shown a blank.
                 */
                'phone' => $contacts->customer($entry->customer_id)
                    ? $entry->customer?->phone
                    : MaskedContact::phone($entry->customer?->phone),
                'contact_hidden' => ! $contacts->customer($entry->customer_id),
                'subject_name' => $entry->subject?->name,
                'service_name' => $entry->service?->name,
                'preferred_days' => $entry->preferred_days ?? [],
                'preferred_times' => $entry->preferred_times?->value,
                'waiting_since' => $entry->created_at?->toIso8601String(),
                'is_active' => $entry->is_active,
            ]);

        return Inertia::render('Waitlist/Index', [
            'entries' => $entries,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'freed' => $this->freedSlot($tenant, $freed, $request->integer('slot') ?: null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', WaitlistEntry::class);
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string'],
            'service_id' => ['required', 'integer'],
            'preferred_days' => ['nullable', 'array'],
            'preferred_times' => ['nullable', 'string'],
        ]);

        try {
            $customer = app(CustomerResolver::class)->resolve(
                $tenant,
                $validated['name'],
                $validated['email'],
                PhoneNumber::toE164($validated['phone'], $tenant->country),
            );
        } catch (CustomerRecordUnavailableException $exception) {
            return back()->withErrors(['email' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['phone' => $exception->getMessage()]);
        }

        $entry = new WaitlistEntry;
        $entry->fill([
            'customer_id' => $customer->id,
            'service_id' => $validated['service_id'],
            'preferred_days' => $validated['preferred_days'] ?? [],
            'preferred_times' => $validated['preferred_times'] ?? PreferredTime::Any->value,
            'is_active' => true,
        ]);
        $entry->save();

        return redirect()->route('waitlist.index')->with('toast', 'Added to the waitlist.');
    }

    /** @return array<string, mixed>|null */
    private function freedSlot(Tenant $tenant, FreedSlots $freed, ?int $preferred): ?array
    {
        $tz = $tenant->timezone;
        $now = CarbonImmutable::now($tz);
        $dayStart = $now->startOfDay();

        $rows = Booking::query()
            ->with(['customer', 'service', 'staff'])
            ->where('starts_at', '>=', $dayStart->utc())
            ->where('starts_at', '<', $dayStart->addDay()->utc())
            ->orderBy('starts_at')
            ->get();

        $annotations = $freed->annotate($tenant, $rows);

        $candidates = $rows->filter(fn (Booking $booking) => $annotations[$booking->id]['is_freed'] ?? false);

        if ($candidates->isEmpty()) {
            return null;
        }

        $booking = $candidates->first(fn (Booking $row) => $row->id === $preferred)
            ?? $candidates->sortBy(fn (Booking $row) => $annotations[$row->id]['gap_starts_at'])->first();

        $annotation = $annotations[$booking->id];
        $starts = CarbonImmutable::parse($annotation['gap_starts_at'])->timezone($tz);

        return [
            'booking_id' => $booking->id,
            'time' => $starts->format('H:i'),
            'date' => $starts->format('D j M'),
            'customer' => $booking->customer?->name,
            'staff' => $booking->staff?->name,
            'minutes' => $annotation['minutes'],
            'waiting' => $annotation['waiting'],
            'offers_sent' => $annotation['offers_sent'],
        ];
    }

    public function offer(Request $request, Booking $booking, FreedSlots $freed, WaitlistOfferer $offerer): RedirectResponse
    {
        $this->authorize('viewAny', WaitlistEntry::class);
        $tenant = current_tenant();
        abort_unless($tenant, 403);

        $day = CarbonImmutable::parse($booking->starts_at)->timezone($tenant->timezone)->startOfDay();

        $rows = Booking::query()
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->get();

        abort_unless($freed->annotate($tenant, $rows)[$booking->id]['is_freed'] ?? false, 404);

        $sent = $offerer->offerForBooking($booking);

        return redirect()->route('waitlist.index')->with(
            'toast',
            $sent === 0 ? 'Nobody on the waitlist matches that slot.' : 'Offer sent to '.$sent.' waiting.',
        );
    }
}
