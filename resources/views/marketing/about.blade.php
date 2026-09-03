@extends('marketing.layout')

{{--
    About.

    **Everything on this page is true and nothing on it is padded.** There is no
    team, no office, no funding round and no founding story, because there is
    none of those things. It is one person in Scotland who has not sold a
    subscription yet, and a page that said otherwise would be found out by the
    first salon owner who asked a follow-up question.

    No name on the byline, by the owner's choice. First person throughout, so
    adding one later is a single line rather than a rewrite.

    No trade is named in the general copy: the reason grooming came first is
    stated as a reason, which is a fact about how the product is being sold,
    rather than as what the product is.
--}}

@section('content')

    {{--
        The trade that went first, read rather than typed.

        This page has to explain why one trade came before the others, which is
        the one place outside `/dog-grooming` where a trade is named at all. It
        is named by reading the live vertical pages out of
        `App\Support\MarketingNav` — the label comes from the `verticals`
        table, the same as it does in the footer — so this page carries no
        grooming string of its own and stays correct when the second one ships.
    --}}
    @php($first = App\Support\MarketingNav::verticalPages()[0] ?? null)

    <section class="hero">
        <div class="orb orb-1"></div>
        <div class="hero-inner">
            <div class="eyebrow">About</div>
            <h1>A new product, built in the open.</h1>
            <p class="sub">
                One person, in Scotland, building booking software for businesses that lose money
                when somebody does not turn up.
            </p>
        </div>
    </section>

    <div class="doc">
        <section>
            <h2>Who is building it</h2>
            <p>
                I am. On my own, from East Kilbride, just outside Glasgow. There is no team, no
                office and no investor. If you email {{ config('product.name') }} you get me, and
                if something breaks at half seven on a Saturday morning I am the person who fixes
                it.
            </p>
            <p>
                That is worth saying plainly rather than dressing up as "a small, focused team".
                It has an obvious downside, which is that I am one person. It also has a real
                upside: you can ask for something and have it in the product the same week, which
                is not true of software sold by anybody larger.
            </p>
        </section>

        <section>
            <h2>Why it exists</h2>
            <p>
                Because the money a small appointment business loses is not lost to a competitor.
                It is lost to a gap in the diary. Somebody cancels on the Friday night, the slot
                is worth real money, and the only way to sell it is to sit there texting people
                one at a time until somebody says yes. Most owners do not have that hour, so the
                slot goes empty.
            </p>
            <p>
                That is a solvable problem, and it does not need a big product to solve it. It
                needs a deposit taken at booking so a no-show costs somebody other than you, and
                a waitlist that texts itself the moment a slot opens. That is what
                {{ config('product.name') }} does. Everything else it does exists to support
                those two things.
            </p>
        </section>

        @if ($first)
            <section>
                <h2>Why {{ Str::lower($first['label']) }} first</h2>
                <p>
                    Because I can drive to a salon, walk in, and show an owner the product on their
                    own diary in fifteen minutes. That is the whole reason. It is not that this
                    trade needs it more than anybody else does. It is that a first customer who
                    has met you is worth ten who have read a web page, and these businesses near
                    me are somewhere I can actually be.
                </p>
                <p>
                    So it got the first price list, the first booking form and the first
                    <a href="{{ $first['href'] }}">page written in its own words</a>. Nothing
                    underneath it is specific to one trade.
                </p>
            </section>
        @endif

        <section>
            <h2>What comes next</h2>
            <p>
                The product was built multi-trade from the first line of code. A trade in
                {{ config('product.name') }} is a row in a table: what it calls the thing being
                booked, what its booking form asks for, and the price list a new account starts
                with. Barbers are already in there. Nail and beauty studios, dog walkers,
                physios, driving instructors and dentists are all the same shape of problem.
            </p>
            <p>
                I am not going to pretend a launch date I do not have. The order those arrive in
                will be decided by which ones ask, and the honest answer today is that
                @if ($first)
                    {{ Str::lower($first['label']) }} is the one that is live.
                @else
                    none of them has a page yet.
                @endif
            </p>
        </section>

        <section>
            <h2>How to reach me</h2>
            <p>
                Email <a href="mailto:{{ $figures->contactEmail() }}">{{ $figures->contactEmail() }}</a>,
                or use the <a href="{{ route('marketing.contact') }}">contact form</a>. If your
                business is near East Kilbride, I will come to you and set it up with you rather
                than sending you a help article.
            </p>
        </section>
    </div>

    @include('marketing.partials.cta-band', [
        'heading' => 'Have a look at it yourself.',
    ])

@endsection
