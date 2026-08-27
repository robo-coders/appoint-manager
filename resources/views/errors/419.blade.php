@extends('errors.layout')

@section('extra')
    {{--
        The one that matters most, and the one that was worst.

        An operator whose session goes stale mid-shift used to land on Laravel's
        stock "419 | Page Expired" — grey, framework-branded, and a genuine dead
        end: no link, no explanation, and the thing they were doing gone.

        `bootstrap/app.php` stores the page they came from as the intended URL
        before this renders, so signing in returns them there rather than to the
        diary. The link above says so, because a promise the person cannot see
        is not one they will act on.
    --}}
    @if ($intended ?? null)
        <p class="foot">
            You were on <span class="ref">{{ $intended }}</span>.
        </p>
    @endif
@endsection
