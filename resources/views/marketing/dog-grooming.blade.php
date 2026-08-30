@extends('marketing.layout')

{{--
    The trade page.

    This is why the marketing site is Blade and not Vue: the words below are a
    vertical's words, and a vertical's copy has no business being bundled into
    the admin SPA (REBUILD.md, phase 11).

    What makes it a trade page rather than the home page with "dog" substituted
    in is that the specifics are real and come from `config/verticals.php` — the
    price list a grooming tenant is actually seeded with, and the fields its
    booking page actually asks for. Both are read through
    `App\Support\MarketingFigures`, so neither can drift from what a new salon
    gets on day one.

    The old version of this page ran "a medium full groom at £45, two no-shows on
    a Saturday is £90". That arithmetic is not carried forward: nothing on this
    page multiplies a slot price by an invented number of missed appointments.
--}}

@section('content')

    @php($words = $figures->verticalWords())

    <section class="hero">
        <div class="wrap split">
            <div class="claim">
                <h1 class="text-34">Saturday's cancellation, sold twice.</h1>
                <p class="sub text-17 text-ink-2">
                    A {{ $words['subject'] }} drops out of your Saturday. Everybody waiting for
                    that service, on that day, gets a text — and the hour goes to whoever answers
                    first instead of sitting empty in the wash bay.
                </p>
                @include('marketing.partials.cta', [
                    'label' => 'Start free trial',
                    'note' => $figures->trialDays().' days. No card.',
                ])
            </div>

            <div class="evidence">
                @include('marketing.partials.refill-sum')
            </div>
        </div>
    </section>

    {{--
        The real seeded price list. Not an illustration — this is the list
        `config/verticals.php` gives a grooming tenant, prices and durations
        included.

        Three columns rather than four: the deposit is £10 on everything except
        the nail clip, which takes none, and that is one sentence underneath
        rather than a fourth figure column squeezed into 375px.
    --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">You do not start from an empty diary</h2>
                <p class="lede">
                    This is the price list already in there when you sign in for the first time.
                    Change any of it, or none of it.
                </p>
            </div>
            <div class="evidence">
                <table class="bill">
                    <caption>The grooming price list we set you up with</caption>
                    <thead>
                        <tr>
                            <th scope="col">Service</th>
                            {{--
                                "Minutes", not "Time" with "min" in every cell.
                                Mono is for figures only, so a unit printed
                                inside a figure cell is prose in the wrong
                                typeface — the unit belongs in the heading,
                                which is Geist because headings always are.
                            --}}
                            <th scope="col" class="fig">Minutes</th>
                            <th scope="col" class="fig">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($figures->seededPriceList() as $service)
                            <tr>
                                <th scope="row">{{ $service['name'] }}</th>
                                <td class="fig">{{ $service['minutes'] }}</td>
                                <td class="fig">{{ $service['price']->formatted() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="footnote">
                    Every groom holds a
                    <span class="font-mono">{{ $figures->deposit()->formatted() }}</span> deposit
                    that comes off the bill on the day. The nail clip does not take one — it is
                    fifteen minutes and asking for a deposit on it would be silly.
                </p>
            </div>
        </div>
    </section>

    {{-- The mechanism, in the trade's own terms. --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">Who gets the text</h2>
                <p class="lede">
                    Not the whole waitlist. The ones who wanted that service, on a day they said
                    they could do.
                </p>
            </div>
            <div class="evidence">
                <dl class="facts">
                    <div>
                        <dt>The same service, not merely the same salon.</dt>
                        <dd>
                            A cancelled full groom does not text somebody waiting for a nail clip.
                            The hour that came free is {{ $figures->slotMinutes() }} minutes long
                            and it goes to somebody who needs {{ $figures->slotMinutes() }} minutes.
                        </dd>
                    </div>
                    <div>
                        <dt>On a day and at a time they can actually make.</dt>
                        <dd>
                            When somebody joins the waitlist they say which days suit and whether
                            they want mornings or afternoons. A Tuesday morning slot does not text
                            the {{ $words['customer'] }} who can only do weekends.
                        </dd>
                    </div>
                    <div>
                        <dt>{{ $figures->offerBatch() }} at a time, for {{ $figures->offerMinutes() }} minutes.</dt>
                        <dd>
                            Then the next {{ $figures->offerBatch() }}, if the first round goes
                            quiet. Sending to one person and waiting is how a Saturday morning slot
                            stays empty until Saturday.
                        </dd>
                    </div>
                </dl>

                <div class="mt-8">
                    @include('marketing.partials.messages')
                </div>
            </div>
        </div>
    </section>

    {{-- What the product knows about a dog, from the vertical's own fields. --}}
    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">It knows the {{ $words['subject'] }}, not just the booking</h2>
                <p class="lede">
                    A grooming diary that only records a name and a time is a calendar with your
                    logo on it.
                </p>
            </div>
            <div class="evidence">
                <p class="max-w-measure text-15">
                    Your booking page asks the owner for the {{ $words['subject'] }}'s details once
                    and remembers them: {{ $figures->subjectFieldList() }}. Next time she books,
                    none of it is asked again.
                </p>
                <p class="mt-4 max-w-measure text-15">
                    The temperament note is the one that earns its place. "Nervous with clippers"
                    appears on the appointment, on the day, next to the time — so whoever is on the
                    table at 10:30 knows before the {{ $words['subject'] }} is on it.
                </p>
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <div class="price">
                    <span class="amt">{{ $figures->monthlyBare() }}</span>
                    <span class="text-17 text-ink-2">a month</span>
                </div>
                <p class="lede">
                    One price for the whole salon. {{ $figures->trialDays() }} days free, no card
                    to start.
                </p>
                <div class="mt-8">
                    @include('marketing.partials.cta', ['label' => 'Start free trial'])
                </div>
            </div>
            <div class="evidence">
                <p class="max-w-measure text-15">
                    No per-booking charge to you, and nothing at all charged to the owners who book
                    with you — no fee on their deposit, no fee on the day.
                </p>
                <p class="mt-4">
                    <a class="m-link text-14" href="{{ route('marketing.pricing') }}">Why we charge you instead</a>
                </p>
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="wrap split">
            <div class="claim">
                <h2 class="text-24">Questions from groomers</h2>
                <p class="lede">The setup ones, mostly.</p>
            </div>
            <div class="evidence">
                <dl class="qa">
                    <div>
                        <dt>What about the book I already have?</dt>
                        <dd>
                            We import names, {{ $words['subjects'] }} and the next fortnight from a
                            spreadsheet before your booking page goes live. For the first ten salons
                            we will come and do it with you.
                        </dd>
                    </div>
                    <div>
                        <dt>What if owners are not on their phones?</dt>
                        <dd>
                            They open a link. There is no account to make and no app to install —
                            a text with a link is the whole interface, which is also why the
                            waitlist works at all.
                        </dd>
                    </div>
                    <div>
                        <dt>Two of us work Saturdays. Does it handle that?</dt>
                        <dd>
                            Yes. Services are assigned to whoever can do them, and a freed hour is
                            offered against the groomer whose hour it was — so a cancellation in
                            your column does not text people about a slot in somebody else's.
                        </dd>
                    </div>
                    <div>
                        <dt>Will asking for a deposit lose me clients?</dt>
                        <dd>
                            The ones who vanish on a Saturday might. A
                            <span class="font-mono">{{ $figures->deposit()->formatted() }}</span>
                            hold comes off the bill on the day, and the owners who want the 9am slot
                            are not the ones who object to it.
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    @include('marketing.partials.no-proof', ['heading' => 'From groomers using it'])

@endsection
