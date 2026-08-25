@php
    $when = $booking->starts_at->timezone($tenant->timezone)->format('l j M, H:i');
    $manage = book_url(null, 'b/'.$booking->public_token);
@endphp
<x-mail::message>
# Your time changed

**{{ $booking->service->name }}** at {{ $tenant->name }} is now {{ $when }}. Your deposit carries over.

<x-mail::button :url="$manage">
Manage booking
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
