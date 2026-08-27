@extends('errors.layout')

@section('extra')
    {{--
        A reference, when there is one to give.

        Not a stack trace and not an exception class: this page is read by a
        salon owner, and the only useful thing they can do with a failure is
        quote an identifier at us. Sentry's event id is that identifier, and it
        is only rendered when Sentry actually captured one — a made-up reference
        is worse than none, because support will search for it.
    --}}
    @if ($reference ?? null)
        <p class="foot">
            If you get in touch, quote <span class="ref">{{ $reference }}</span> — it takes us
            straight to what happened.
        </p>
    @endif
@endsection
