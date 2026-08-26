<?php

namespace App\Http\Controllers;

use App\Enums\PreferredTime;
use App\Models\Customer;
use App\Models\Service;
use App\Models\WaitlistEntry;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WaitlistController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', WaitlistEntry::class);

        $entries = WaitlistEntry::query()
            ->with(['customer', 'service'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (WaitlistEntry $entry) => [
                'id' => $entry->id,
                'customer_id' => $entry->customer_id,
                'customer_name' => $entry->customer?->name,
                'phone' => $entry->customer?->phone,
                'service_name' => $entry->service?->name,
                'preferred_days' => $entry->preferred_days ?? [],
                'preferred_times' => $entry->preferred_times?->value,
                'waiting_since' => $entry->created_at?->toIso8601String(),
                'is_active' => $entry->is_active,
            ]);

        return Inertia::render('Waitlist/Index', [
            'entries' => $entries,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
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

        $customer = Customer::query()->where('email', $validated['email'])->first();

        if ($customer === null) {
            $customer = new Customer;
            $customer->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => PhoneNumber::toE164($validated['phone'], $tenant->country),
            ]);
            $customer->save();
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
}
