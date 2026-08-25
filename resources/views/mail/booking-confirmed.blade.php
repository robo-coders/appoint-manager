@php
    $when = $booking->starts_at->timezone($tenant->timezone)->format('l j M, H:i');
    $manage = book_url(null, 'b/'.$booking->public_token);
@endphp
<x-mail::message>
# You're booked at {{ $tenant->name }}

**{{ $booking->service->name }}** on {{ $when }}@if($booking->staff)
 with {{ $booking->staff->name }}
@endif.

@if($tenant->address_line_1)
{{ $tenant->address_line_1 }}, {{ $tenant->city }} {{ $tenant->postcode }}
@endif

Total {{ $booking->price_at_booking->formatted() }}. Deposit paid {{ $booking->deposit_at_booking->formatted() }}. The rest is due on the day.

<x-mail::button :url="$manage">
Manage booking
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
