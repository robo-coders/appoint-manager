@extends('marketing.layout')
@section('content')
@php
    // Built as one expression on purpose: writing it as `hello@{{ ... }}` makes Blade
    // read `@{{` as its escape directive and print the braces verbatim.
    $address = 'hello@'.(parse_url(config('app.url'), PHP_URL_HOST) ?: 'example.com');
@endphp
<section class="mx-auto max-w-3xl px-4 py-16">
    <h1 class="font-display text-34">Contact</h1>
    <p class="mt-4 text-17">Email <a class="underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink" href="mailto:{{ $address }}">{{ $address }}</a>. For the first ten salons, we will set you up ourselves.</p>
    <div class="mt-8">
        @include('marketing.partials.cta-quiet', ['label' => 'Start free trial'])
    </div>
</section>
@endsection
