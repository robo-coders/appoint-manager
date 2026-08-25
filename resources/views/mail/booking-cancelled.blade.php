@php
    $when = $booking->starts_at->timezone($tenant->timezone)->format('l j M, H:i');
@endphp
<x-mail::message>
# Booking cancelled

Your {{ $booking->service->name }} at {{ $tenant->name }} on {{ $when }} has been cancelled.

Refund: {{ $refundStatus }}

{{ config('app.name') }}
</x-mail::message>
