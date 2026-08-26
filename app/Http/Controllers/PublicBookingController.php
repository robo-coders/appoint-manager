<?php

namespace App\Http\Controllers;

use App\Enums\BookingSource;
use App\Enums\PreferredTime;
use App\Exceptions\PaymentSetupFailedException;
use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\PublicBooking\StorePublicBookingRequest;
use App\Http\Requests\Waitlist\JoinWaitlistRequest;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Availability\AvailabilityEngine;
use App\Services\Booking\AppointmentSuggester;
use App\Services\Booking\BookingService;
use App\Support\AvailabilityCache;
use App\Support\PhoneNumber;
use App\Support\ProposalPayload;
use App\Support\ReturningCustomer;
use App\Support\ServicePayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class PublicBookingController extends Controller
{
    /**
     * The proposal.
     *
     * There is no calendar on this page any more. `AppointmentSuggester` decides
     * one finished appointment and three spread ways out of it, and every one of
     * them carries the phrase that justifies it — see that class, and phase 4 in
     * DECISIONS.md. The date picker still exists, reachable from the quietest
     * control on the page, for the customer whose answer is none of the four.
     */
    public function show(Request $request, AppointmentSuggester $suggester): Response
    {
        $tenant = $this->tenant($request);

        $services = Service::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => ServicePayload::toArray($service))
            ->values();

        // Recognised by the manage-link cookie or a reminder link, never by a
        // typed-in email address. See ReturningCustomer.
        $customer = ReturningCustomer::forRequest($request, $tenant);

        $requested = $request->filled('service')
            ? Service::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->find($request->integer('service'))
            : null;

        $suggestion = $suggester->suggest($tenant, $customer, $requested);

        $response = response()->view('booking', [
            'tenant' => $tenant,
            'props' => [
                'tenant' => [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'timezone' => $tenant->timezone,
                    'currency' => $tenant->currency,
                    'takes_deposits' => $tenant->takesDeposits(),
                    'city' => $tenant->city,
                    'postcode' => $tenant->postcode,
                    'address_line_1' => $tenant->address_line_1,
                    'phone' => $tenant->phone,
                ],
                'stripePublishableKey' => config('services.stripe.key'),
                'services' => $services,
                'suggestion' => ProposalPayload::suggestion($suggestion, $tenant),
                'vertical' => $tenant->vertical(),
                'today' => CarbonImmutable::now($tenant->timezone)->toDateString(),
                'urls' => [
                    'page' => route('public.booking.show', $tenant->slug, absolute: false),
                    'availability' => route('public.booking.availability', $tenant->slug, absolute: false),
                    'store' => route('public.booking.store', $tenant->slug, absolute: false),
                    'waitlist' => route('public.booking.waitlist', $tenant->slug, absolute: false),
                ],
            ],
        ]);

        /*
         * A reminder link carried the token in the URL. Remember it, so the next
         * visit is recognised without one — and so the token stops travelling in
         * a URL that ends up in a browser history and a referrer header.
         */
        if ($customer !== null && is_string($request->query('ref'))) {
            $response->withCookie(ReturningCustomer::remember(
                ReturningCustomer::token($request),
                $request->secure(),
            ));
        }

        return $response;
    }

    public function availability(Request $request, AvailabilityEngine $engine): JsonResponse
    {
        $tenant = $this->tenant($request);
        $service = Service::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->findOrFail($request->integer('service'));

        $fromDate = (string) $request->query('from');
        $toDate = (string) $request->query('to');

        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) === 1, 422);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) === 1, 422);

        $staffId = $request->integer('staff') ?: null;
        $staff = $staffId ? User::query()->where('tenant_id', $tenant->id)->find($staffId) : null;

        $cacheKey = AvailabilityCache::key($tenant->id, $service->id, $fromDate, $toDate, $staff?->id);

        $payload = Cache::remember($cacheKey, (int) config('booking.availability_cache_ttl'), function () use ($engine, $tenant, $service, $fromDate, $toDate, $staff) {
            $from = CarbonImmutable::parse($fromDate.' 00:00:00', $tenant->timezone)->utc();
            $to = CarbonImmutable::parse($toDate.' 00:00:00', $tenant->timezone)->addDay()->utc();

            /*
             * Two questions, two answers: what the day is, and what is left of
             * it. The picker draws every candidate start and strikes through the
             * ones that have gone, because a grid with three times in it cannot
             * tell a customer whether the salon is busy or shut, and a grid with
             * none in it reads as a broken page.
             */
            $free = $engine->slotsFor($tenant, $service, $from, $to, $staff);
            $grid = $engine->gridFor($tenant, $service, $from, $to, $staff);

            $freeIds = [];
            foreach ($free as $slot) {
                $freeIds[$slot->startsAt->utc()->getTimestamp()] = $slot->staffIds;
            }

            $days = [];
            $cursor = CarbonImmutable::parse($fromDate, $tenant->timezone)->startOfDay();
            $last = CarbonImmutable::parse($toDate, $tenant->timezone)->startOfDay();

            while ($cursor->lte($last)) {
                $days[$cursor->toDateString()] = [];
                $cursor = $cursor->addDay();
            }

            foreach ($grid as $slot) {
                $local = $slot->startsAt->timezone($tenant->timezone);
                $stamp = $slot->startsAt->utc()->getTimestamp();
                $available = array_key_exists($stamp, $freeIds);

                $days[$local->toDateString()][] = [
                    'starts_at' => $slot->startsAt->utc()->toIso8601String(),
                    'starts_at_local' => $local->format('H:i'),
                    'staff_ids' => $available ? $freeIds[$stamp] : [],
                    'available' => $available,
                    // Morning and afternoon, which is how the picker groups.
                    'half' => $local->hour < 12 ? 'am' : 'pm',
                ];
            }

            return ['days' => $days];
        });

        return response()->json($payload);
    }

    public function store(StorePublicBookingRequest $request, BookingService $bookings): JsonResponse
    {
        $tenant = $this->tenant($request);
        $service = Service::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->findOrFail($request->integer('service_id'));

        $startsAt = CarbonImmutable::parse($request->string('starts_at')->toString())->utc();

        try {
            $staff = $this->resolveStaff($tenant, $service, $startsAt, $request->integer('staff_id') ?: null);

            $customer = $this->findOrCreateCustomer(
                $tenant,
                $request->string('name')->toString(),
                $request->string('email')->toString(),
                $request->string('phone')->toString(),
            );

            $subject = $this->resolveSubject($tenant, $customer, $request);

            $booking = $bookings->create(
                $tenant,
                $service,
                $staff,
                $customer,
                $startsAt,
                BookingSource::Online,
                $subject,
            );
        } catch (SlotUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (PaymentSetupFailedException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $clientSecret = $bookings->lastClientSecret();

        return response()->json([
            'booking' => [
                'public_token' => $booking->public_token,
                'status' => $booking->status->value,
                'deposit_status' => $booking->deposit_status->value,
                'price' => $booking->price_at_booking->toArray(),
                'deposit' => $booking->deposit_at_booking->toArray(),
                'manage_url' => book_url(null, 'b/'.$booking->public_token),
            ],
            'payment' => $clientSecret ? [
                'client_secret' => $clientSecret,
                'connected_account' => $tenant->stripe_account_id,
            ] : null,
        ], 201);
    }

    public function waitlist(JoinWaitlistRequest $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        $service = Service::query()->where('tenant_id', $tenant->id)->findOrFail($request->integer('service_id'));

        try {
            $customer = $this->findOrCreateCustomer(
                $tenant,
                $request->string('name')->toString(),
                $request->string('email')->toString(),
                $request->string('phone')->toString(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $entry = new WaitlistEntry;
        $entry->forceFill([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'preferred_days' => $request->input('preferred_days', []),
            'preferred_times' => $request->string('preferred_times')->toString() ?: PreferredTime::Any->value,
            'notes' => $request->input('notes'),
            'is_active' => true,
        ]);
        $entry->save();

        return response()->json(['id' => $entry->id], 201);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('public_tenant');

        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function resolveStaff(Tenant $tenant, Service $service, CarbonImmutable $startsAt, ?int $staffId): User
    {
        $slots = app(AvailabilityEngine::class)->slotsFor(
            $tenant,
            $service,
            $startsAt->timezone($tenant->timezone)->startOfDay()->utc(),
            $startsAt->timezone($tenant->timezone)->addDay()->startOfDay()->utc(),
        );

        $ids = $slots->staffIdsFor($startsAt);

        if ($staffId !== null && in_array($staffId, $ids, true)) {
            return User::query()->where('tenant_id', $tenant->id)->findOrFail($staffId);
        }

        if ($ids === []) {
            throw SlotUnavailableException::forSlot();
        }

        return User::query()->where('tenant_id', $tenant->id)->findOrFail($ids[0]);
    }

    /**
     * Matched on email only, and never updated from here.
     *
     * A public booking is unauthenticated: whoever typed this address may not be the
     * person who owns it. Writing the submitted name or phone onto an existing record
     * would let a stranger rewrite a real customer's contact details, so an existing
     * record is returned untouched and the submitted details stay on the booking.
     */
    private function findOrCreateCustomer(Tenant $tenant, string $name, string $email, string $phone): Customer
    {
        $customer = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->first();

        if ($customer !== null) {
            return $customer;
        }

        $customer = new Customer;
        $customer->forceFill([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone === '' ? null : PhoneNumber::toE164($phone, $tenant->country),
        ]);
        $customer->save();

        return $customer;
    }

    private function resolveSubject(Tenant $tenant, Customer $customer, StorePublicBookingRequest $request): ?Subject
    {
        if ($request->filled('subject_id')) {
            return Subject::query()
                ->where('tenant_id', $tenant->id)
                ->where('customer_id', $customer->id)
                ->findOrFail($request->integer('subject_id'));
        }

        if (! $request->filled('subject_name')) {
            return null;
        }

        $subject = new Subject;
        $subject->forceFill([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'name' => $request->string('subject_name')->toString(),
            'attributes' => $request->input('subject_attributes', []),
        ]);
        $subject->save();

        return $subject;
    }
}
