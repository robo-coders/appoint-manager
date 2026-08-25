@extends('marketing.layout')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-16">
    <h1 class="font-display text-34">One plan. £39 a month or £390 a year.</h1>
    <p class="mt-4 max-w-2xl text-17 text-ink-2">Thirty days free. No card up front. Same product either way. Yearly is two months free.</p>
    <div class="mt-8 grid gap-4 md:grid-cols-2">
        <div class="rounded border border-rule bg-white p-6">
            <p class="text-13 text-ink-2">Monthly</p>
            <p class="mt-1 font-display text-24">£39</p>
            <div class="mt-4">
                @include('marketing.partials.cta-quiet', ['label' => 'Start free trial'])
            </div>
        </div>
        <div class="rounded border border-rule bg-white p-6">
            <p class="text-13 text-ink-2">Yearly</p>
            <p class="mt-1 font-display text-24">£390</p>
            <div class="mt-4">
                @include('marketing.partials.cta-quiet', ['label' => 'Start free trial'])
            </div>
        </div>
    </div>
</section>
@endsection
