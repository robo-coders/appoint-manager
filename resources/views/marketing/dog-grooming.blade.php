@extends('marketing.layout')

@section('content')
<section class="mx-auto max-w-5xl px-4 py-16">
    <h1 class="font-display text-34">Stop empty tables on a Saturday</h1>
    <p class="mt-4 max-w-2xl text-17 text-ink-2">
        A groomer books a full day, then two dogs do not arrive. That is not a marketing problem.
        It is a deposit problem. Hold the slot with a small payment when the owner books.
    </p>
    <div class="mt-8">
        @include('marketing.partials.cta', ['label' => 'Start free trial'])
    </div>
</section>
<section class="mx-auto max-w-5xl px-4 py-8">
    <h2 class="font-display text-20">How a groomer actually uses it</h2>
    <p class="mt-2 max-w-2xl">The public page lists full grooms, bath and blow dry, nail clips. The owner picks a dog, a time, and pays the deposit. You see it on the diary the same minute.</p>
    <div class="mt-6 overflow-hidden rounded border border-rule bg-white p-4" aria-label="Grooming diary">
        <div class="mb-3 text-12 text-ink-2">Saturday book</div>
        <div class="grid gap-2 text-13">
            <div class="flex items-center justify-between gap-4 rounded border border-rule px-3 py-2"><span>09:00 Full groom — medium</span><span class="text-ink-2">Buster · £10 held</span></div>
            <div class="flex items-center justify-between gap-4 rounded border border-rule px-3 py-2"><span>10:30 Bath and blow dry</span><span class="text-ink-2">Daisy · paid</span></div>
        </div>
    </div>
</section>
<section class="mx-auto max-w-5xl px-4 py-8">
    <h2 class="font-display text-20">The deposit maths for a salon</h2>
    <p class="mt-2 max-w-2xl">A medium full groom at £45. Two no-shows on a Saturday is £90 and a wasted wash-bay. A £10 hold is not a punishment. It is how you keep the book honest.</p>
    <div class="mt-6">
        @include('marketing.partials.cta-quiet', ['label' => 'Start free trial'])
    </div>
</section>
<section class="mx-auto max-w-5xl px-4 py-8" aria-label="What owners say">
    <h2 class="font-display text-20">From groomers using it</h2>
    <p class="mt-2 max-w-2xl text-ink-2">Empty on purpose until a real salon writes something we can print.</p>
</section>
<section class="mx-auto max-w-5xl px-4 py-8">
    <h2 class="font-display text-20">Questions from groomers</h2>
    <dl class="mt-4 max-w-2xl space-y-4 text-14">
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">Is it hard to set up?</dt>
            <dd class="mt-1 text-ink-2">We copy a standard grooming price list and hours for you. You are not starting from a blank diary.</dd>
        </div>
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">What if owners are not on their phones?</dt>
            <dd class="mt-1 text-ink-2">They open a link. They do not need an account. A confirmation text is enough.</dd>
        </div>
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">What about the book I already have?</dt>
            <dd class="mt-1 text-ink-2">We import names, dogs, and the next fortnight from a spreadsheet before you switch the page on.</dd>
        </div>
        <div class="rounded border border-rule bg-white p-4">
            <dt class="font-medium">Will asking for a deposit lose me clients?</dt>
            <dd class="mt-1 text-ink-2">The ones who vanish on Saturday might. The ones who want the 9am slot will pay £10 to keep it.</dd>
        </div>
    </dl>
    <div class="mt-8">
        @include('marketing.partials.cta', ['label' => 'Start free trial'])
    </div>
</section>
@endsection
