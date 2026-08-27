@extends('errors.layout')

@section('extra')
    {{--
        No links at all, from `ErrorPage::ways()`: every one of them would 503
        as well, and a page that offers you a tap that cannot work is worse than
        one that offers none.

        This page must render with the database down and the queue dead. It
        makes no query, mounts no Inertia page, and reads no Vite manifest — see
        `errors/layout.blade.php`. Asserted in `tests/Feature/Errors/`, and
        checked by hand against a stopped MySQL.
    --}}
    @if ($retryAfter ?? null)
        <p class="foot">Try again in about {{ $retryAfter }}.</p>
    @endif
@endsection
