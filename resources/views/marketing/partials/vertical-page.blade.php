{{--
    The trade page, for any vertical.

    **This file is the template and holds no trade's words.** Everything a
    groomer reads comes from one of two places: the `$copy` array the calling
    view hands in, and `App\Support\VerticalFigures`, which reads the vertical's
    own row in `verticals` — the label, the subject noun, the price list a new
    tenant is actually seeded with, and the extra fields its booking page asks
    for. Nothing on this page is invented and nothing is typed twice.

    So `/barbers` is: a route, a controller method, and a copy of
    `marketing/dog-grooming.blade.php` with `'barber'` as the key and new
    strings. Not a rebuild. The `barber` vertical is already a row in the
    database with its own price list, which is why the price table and the
    subject-field sentence need no work at all for it.

    Required in: `$vertical` (a VerticalFigures), `$copy` (below), `$figures`.

    `$copy` keys:
      headline, sub                — the hero
      scenarios[]                  — {label, body}: three recognisable days
      sumLede, sumCaption          — the one-refilled-appointment sum
      priceHeading, priceLede, priceFootnote
      textHeading, textLede
      subjectHeading, subjectLede, subjectBody[]
      questions[]                  — {q, a}
      proofHeading
      ctaHeading, ctaNote
      diary                        — {day, status, rows[]}
--}}

<section class="hero">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="hero-inner">
        {{-- The trade's own name for itself, from the `verticals` table. --}}
        <div class="eyebrow">{{ $vertical->label() }}</div>
        <h1>{{ $copy['headline'] }}</h1>
        <p class="sub">{{ $copy['sub'] }}</p>
        <div class="hero-cta">
            <a class="pill" href="{{ app_url('register') }}">Start your free trial</a>
            <a class="pill-ghost" href="{{ route('marketing.how-it-works') }}">See how it works</a>
        </div>
        <p class="hero-price">
            <span class="fig">{{ $figures->monthlyBare() }}</span>/month,
            <span class="fig">{{ $figures->trialDays() }}</span>-day free trial. No card to start.
        </p>
    </div>

    {{--
        The same diary as the home page, with this trade's own example data:
        real service names off the seeded price list, and the lengths those
        services actually take.
    --}}
    <div class="visual">
        <div class="diary">
            <div class="diary-head">
                <span class="day">{{ $copy['diary']['day'] }}</span>
                <span class="status">{{ $copy['diary']['status'] }}</span>
            </div>
            <div class="diary-body">
                <div class="times">
                    @foreach ($copy['diary']['rows'] as $row)
                        <div class="trow">{{ $row['time'] }}</div>
                    @endforeach
                </div>
                <div class="slots">
                    @foreach ($copy['diary']['rows'] as $row)
                        <div class="srow">
                            @if (($row['state'] ?? null) === 'open')
                                <div class="slot open">{{ $row['name'] }}</div>
                            @else
                                <div class="slot {{ ($row['state'] ?? null) === 'reclaimed' ? 'reclaimed' : '' }}">
                                    <span class="name">{{ $row['name'] }}</span>
                                    <span class="service">{{ $row['service'] }}</span>
                                    @isset($row['tag'])
                                        <span class="tag">{{ $row['tag'] }}</span>
                                    @endisset
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <p class="caption">{{ $copy['diary']['caption'] }}</p>
    </div>
</section>

{{-- Three days a person in this trade will recognise. --}}
<section class="section">
    <div class="section-inner">
        <h2>{{ $copy['scenarioHeading'] }}</h2>
        <div class="rows">
            @foreach ($copy['scenarios'] as $scenario)
                <div class="row">
                    <div class="label">{{ $scenario['label'] }}</div>
                    <div class="body">{!! $scenario['body'] !!}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{--
    The two sections below are the only ones built on the vertical's price list,
    so they are drawn only when there is one. A vertical with no services renders
    a shorter page rather than a 500 — a marketing page must not go down because
    a row is missing, and leaving a section out invents nothing.
--}}
@if ($vertical->hasPriceList())

{{--
    The arithmetic behind "one refilled appointment covers the month", shown as
    working.

    **There is no monthly volume in this sum, and that is the point.** An
    earlier version of the site compared a competitor's per-booking fee against
    our monthly price, and that argument only wins above roughly thirty
    appointments a month, so the small salon this product is for read it and
    correctly concluded we were the expensive option. This sum is true at twenty
    appointments and at eighty, because volume is not in it.

    Both figures are read, not typed: the appointment price is the vertical's
    own seeded price list and the monthly price is `config('billing')`. The
    difference is subtracted rather than stated, so a price change moves the
    whole sum instead of leaving a stale total behind.
--}}
<section class="split">
    <div>
        <h2>{{ $copy['sumHeading'] }}</h2>
        <p class="lede">{{ $copy['sumLede'] }}</p>
    </div>
    <div>
        <table class="bill">
            <caption>{{ $copy['sumCaption'] }}</caption>
            <tbody>
                <tr>
                    <th scope="row">One {{ lcfirst($vertical->slotName()) }}, at the price we set you up with</th>
                    <td class="fig">{{ $vertical->slot()->formatted() }}</td>
                </tr>
                <tr>
                    <th scope="row">{{ config('product.name') }}, one month</th>
                    <td class="fig">&minus;{{ $figures->monthly()->formatted() }}</td>
                </tr>
                <tr class="sum">
                    <th scope="row">
                        @if ($vertical->oneRefillCovers())
                            Still yours, once the month is paid for
                        @else
                            Left to find, after one refilled appointment
                        @endif
                    </th>
                    <td class="fig big">{{ $vertical->surplus()->formatted() }}</td>
                </tr>
            </tbody>
        </table>
        <p class="footnote">
            Put your own price in the top line. Anything at or above
            <span class="fig">{{ $figures->monthly()->formatted() }}</span> and one refilled
            cancellation has paid for the month. It does not matter whether you take twenty
            appointments a month or eighty, because how many you take is not in the sum.
        </p>
    </div>
</section>

{{-- The real seeded price list. Not an illustration. --}}
<section class="split">
    <div>
        <h2>{{ $copy['priceHeading'] }}</h2>
        <p class="lede">{{ $copy['priceLede'] }}</p>
    </div>
    <div>
        <table class="bill">
            <caption>{{ $copy['priceCaption'] }}</caption>
            <thead>
                <tr>
                    <th scope="col">Service</th>
                    {{--
                        "Minutes", not "Time" with "min" in every cell. Mono is
                        for figures only, so a unit printed inside a figure cell
                        is prose in the wrong typeface. The unit belongs in the
                        heading.
                    --}}
                    <th scope="col" class="fig">Minutes</th>
                    <th scope="col" class="fig">Price</th>
                    <th scope="col" class="fig">Deposit</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($vertical->priceList() as $service)
                    <tr>
                        <th scope="row">{{ $service['name'] }}</th>
                        <td class="fig">{{ $service['minutes'] }}</td>
                        <td class="fig">{{ $service['price']->formatted() }}</td>
                        <td class="fig">{{ $service['deposit']->amount === 0 ? '—' : $service['deposit']->formatted() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="footnote">{{ $copy['priceFootnote'] }}</p>
    </div>
</section>

@endif

{{-- The mechanism, in the trade's own terms. --}}
<section class="split">
    <div>
        <h2>{{ $copy['textHeading'] }}</h2>
        <p class="lede">{{ $copy['textLede'] }}</p>
    </div>
    <div>
        <dl class="facts">
            @foreach ($copy['facts'] as $fact)
                <div>
                    <dt>{{ $fact['dt'] }}</dt>
                    <dd>{{ $fact['dd'] }}</dd>
                </div>
            @endforeach
        </dl>

        {{--
            The two texts the waitlist actually sends.

            **These are quotations, not copywriting.** Both bodies are the real
            strings from `App\Services\Notifications\Notifier` —
            `waitlistOffer()` and `waitlistGone()` — after the salon-name prefix
            the method adds. `MarketingNavTest` asserts them against that class,
            so a rewrite there fails a test rather than leaving this page
            quietly wrong.

            **No salon name.** An earlier version put the demo tenant's name on
            these lines and labelled it illustrative. A name on a marketing page
            that is not a customer is invented, and labelling it does not make
            it not invented. The prefix is the salon's own name when the text
            goes out; here it is described, not substituted.

            **It is deliberately not a two-way exchange.** A mocked-up
            conversation with the customer texting "yes" back would read better
            and would be a lie: nothing in this product parses an inbound SMS.
            The offer is claimed by opening the link, and the first person
            through it gets the slot.
        --}}
        <div class="thread">
            <div class="msg">
                {{-- "SMS" is a word, so it is not mono. Mono is for figures. --}}
                <p class="msg-meta">
                    <span>To the {{ $figures->offerBatch() }} people waiting</span>
                    <span>SMS</span>
                </p>
                <p class="body"><span class="quiet">the salon's name</span>: a slot is free. Claim: <span class="quiet">…/offer/9f2c</span></p>
            </div>

            <div class="msg msg-later">
                <p class="msg-meta">
                    <span>To everyone who was not first</span>
                    <span>SMS</span>
                </p>
                <p class="body"><span class="quiet">the salon's name</span>: that slot was taken. We will text if another opens.</p>
            </div>
        </div>

        <p class="footnote">
            Word for word what goes out, after your own salon name. Nobody replies to a text: the
            link opens the slot, and the first person to take it has it. Everyone else gets the
            second message, which is the one most systems do not bother to send.
        </p>
    </div>
</section>

{{-- What the product knows about the subject, from the vertical's own fields. --}}
@if ($vertical->hasSubjectFields())
    <section class="split">
        <div>
            <h2>{{ $copy['subjectHeading'] }}</h2>
            <p class="lede">{{ $copy['subjectLede'] }}</p>
        </div>
        <div>
            <p class="prose">
                Your booking page asks for the {{ $vertical->subject() }}'s details once and
                remembers them: {{ $vertical->subjectFieldList() }}. Next time they book, none of
                it is asked again.
            </p>
            @foreach ($copy['subjectBody'] as $paragraph)
                <p class="prose">{!! $paragraph !!}</p>
            @endforeach
        </div>
    </section>
@endif

{{-- The setup questions, which are the ones people actually ask. --}}
<section class="split">
    <div>
        <h2>{{ $copy['questionHeading'] }}</h2>
        <p class="lede">{{ $copy['questionLede'] }}</p>
    </div>
    <div>
        <dl class="qa">
            @foreach ($copy['questions'] as $question)
                <div>
                    <dt>{{ $question['q'] }}</dt>
                    <dd>{!! $question['a'] !!}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</section>

{{--
    The empty testimonial section, kept empty on purpose.

    There are no customers yet, so there is no social proof of any kind on this
    surface: no counts, no "trusted by", no quotes, no logos, no ratings, no
    press. Saying so in one line is worth more than the section being absent. A
    page with nothing where the proof goes reads as a page that has not thought
    about it, and this one has.
--}}
<section class="split">
    <div>
        <h2>{{ $copy['proofHeading'] }}</h2>
    </div>
    <div>
        <p class="prose">
            Nothing here yet. When a real salon writes something we can print, it goes here with
            their name on it. Not before.
        </p>
    </div>
</section>

@include('marketing.partials.cta-band', [
    'heading' => $copy['ctaHeading'],
    'note' => $copy['ctaNote'],
])
