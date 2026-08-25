<x-mail::message>
# Tomorrow at {{ $tenant->name }}

@forelse ($bookings as $booking)
- {{ $booking->starts_at->timezone($tenant->timezone)->format('H:i') }} {{ $booking->customer->name }} — {{ $booking->service->name }}
@empty
No bookings tomorrow.
@endforelse

{{ config('app.name') }}
</x-mail::message>
