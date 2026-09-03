@extends('marketing.layout')

{{--
    404, on the marketing host only.

    The other three surfaces keep `errors/404.blade.php`, which is deliberately
    self-contained — no `@vite`, no queries — because it also renders the 500 and
    the 503, when the build or the database may not be there. A 404 on the
    marketing host has no such excuse: the app is up, the build is fine, and the
    person reading it is a stranger who has just been dropped out of the
    product's visual language into a grey framework page.

    So this one is the marketing site, and `bootstrap/app.php` routes 404s on the
    marketing surface here. `noindex`, because a soft 404 in an index is worse
    than none.
--}}

@section('content')

    <section class="gone">
        <div class="orb orb-1"></div>
        <div class="gone-inner">
            <p class="code">404</p>
            <h1>There is nothing at this address.</h1>
            <p>
                The page may have moved, or a character may have been dropped from the link.
                Nothing is broken and nothing has been lost.
            </p>
            <div class="gone-ways">
                <a class="pill" href="{{ route('marketing.home') }}">Back to the start</a>
                <a class="pill-ghost" href="{{ route('marketing.how-it-works') }}">How it works</a>
                <a class="pill-ghost" href="{{ route('marketing.pricing') }}">Pricing</a>
            </div>
        </div>
    </section>

@endsection
