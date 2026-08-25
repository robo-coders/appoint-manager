@extends('marketing.layout')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-16">
    <h1 class="font-display text-34">Stop losing money to no-shows</h1>
    <p class="mt-4 max-w-2xl text-17 text-ink-2">
        Someone books, then does not turn up. The chair sits empty. A small deposit at booking time
        is the difference between a wasted morning and a full book. Thirty days free. No card.
    </p>
    <div class="mt-8">
        @include('marketing.partials.cta', ['label' => 'Start free trial'])
    </div>
</section>
<section class="mx-auto max-w-5xl px-4 py-8">
    <h2 class="font-display text-20">Thirty seconds, then the diary</h2>
    <p class="mt-2 max-w-2xl text-ink-2">This is the real product, not a drawing of it.</p>
    <div class="mt-6 overflow-hidden rounded border border-rule bg-white p-4" aria-label="Diary screenshot">
        <div class="mb-3 text-12 text-ink-2">Thursday · Europe/London</div>
        <div class="grid gap-2 text-13">
            <div class="flex items-center justify-between gap-4 rounded border border-rule px-3 py-2"><span>09:00 Full groom</span><span class="text-ink-2">Paid deposit £10</span></div>
            <div class="flex items-center justify-between gap-4 rounded border border-rule px-3 py-2"><span>10:30 Bath and blow dry</span><span class="text-ink-2">Confirmed</span></div>
            <div class="flex items-center justify-between gap-4 rounded border border-dashed border-rule px-3 py-2 text-ink-2"><span>12:00</span><span>Open</span></div>
        </div>
    </div>
</section>
<section class="mx-auto max-w-5xl px-4 py-8">
    <h2 class="font-display text-20">The deposit maths</h2>
    <p class="mt-2 max-w-2xl">
        A £45 slot. Three no-shows a week is £135 gone, every week. Ask for £10 when they book.
        If they come, it comes off the bill. If they do not, you are not working for free.
        People who intend to show up still book.
    </p>
    <div class="mt-6">
        @include('marketing.partials.cta-quiet', ['label' => 'Start free trial'])
    </div>
</section>
<section class="mx-auto max-w-5xl px-4 py-8" aria-label="What owners say">
    <h2 class="font-display text-20">From salons using it</h2>
    <p class="mt-2 max-w-2xl text-ink-2">Nothing here yet. When a real owner writes something we will put it here — not a made-up quote.</p>
</section>
<section class="mx-auto max-w-5xl px-4 py-8">
    <h2 class="font-display text-20">Questions we actually get</h2>
    <dl class="mt-4 max-w-2xl space-y-4 text-14">
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">Is it hard to set up?</dt>
            <dd class="mt-1 text-ink-2">We set the first ten salons up by hand. You can be live the same week, including your existing clients.</dd>
        </div>
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">What if my customers are not techy?</dt>
            <dd class="mt-1 text-ink-2">They get a link. They pick a time. They pay a deposit in the browser they already use. No app to install.</dd>
        </div>
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">What happens to my existing bookings?</dt>
            <dd class="mt-1 text-ink-2">We import them. The public page can stay private until you are ready, via a preview link.</dd>
        </div>
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">Will deposits upset people?</dt>
            <dd class="mt-1 text-ink-2">A clear sentence on the booking page is enough: the deposit holds the slot and comes off the bill. The people who object were the no-shows.</dd>
        </div>
    </dl>
    <div class="mt-8">
        @include('marketing.partials.cta', ['label' => 'Start free trial'])
    </div>
</section>
@endsection
