@php
    $when = $booking->starts_at->timezone($tenant->timezone)->format('l j M, H:i');
@endphp
<x-mail::message>
# Booking cancelled

{{ $booking->customer->name }} cancelled **{{ $booking->service->name }}** on {{ $when }}.

{{ config('app.name') }}
</x-mail::message>
