@extends('marketing.layout')

{{--
    Restyled onto the new frame, copy unchanged.
--}}

@section('content')
    @php
        // Built as one expression on purpose: writing it as `hello@{{ ... }}` makes Blade
        // read `@{{` as its escape directive and print the braces verbatim.
        $address = 'hello@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'example.com');
    @endphp

    <section class="hero">
        <div class="wrap">
            <h1 class="text-34">Contact</h1>
            <div class="prose mt-6 text-17">
                <p>
                    Email <a class="m-link" href="mailto:{{ $address }}">{{ $address }}</a>. For the
                    first ten salons, we will set you up ourselves.
                </p>
            </div>
            <div class="mt-8">
                @include('marketing.partials.cta-quiet', ['label' => 'Start free trial'])
            </div>
        </div>
    </section>
@endsection
