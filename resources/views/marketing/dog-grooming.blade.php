@extends('marketing.layout')

{{--
    The dog grooming trade page.

    **This file is copy and example data. The page itself is
    `marketing/partials/vertical-page.blade.php`.** That split is the whole
    point: the product is multi-vertical from day one, and the second trade page
    has to be a copy of this file with new strings rather than a second layout
    to keep in step with this one.

    So adding `/barbers` is:
      1. a route and a controller method,
      2. a copy of this file with `'barber'` as the vertical key,
      3. new strings in `$copy`, and one entry in `App\Support\MarketingNav`.

    Nothing in `vertical-page.blade.php` changes, and neither does the header or
    the footer.

    What makes this a trade page rather than the home page with "dog"
    substituted in is that the specifics are real. The price list, the service
    lengths, the deposits and the extra fields the booking page asks for all
    come from the groomer row in `verticals`, read through
    `App\Support\VerticalFigures`, so none of it can drift from what a new salon
    is actually given on day one.

    This is also why the marketing site is Blade and not Vue: a vertical's copy
    has no business being bundled into the admin SPA (REBUILD.md, phase 11).
--}}

@section('content')

    {{--
        Read once, here, so the copy below can quote the salon's real service
        lengths rather than restating them. `$vertical` is the same object the
        template gets.
    --}}
    @php($groomer = $figures->vertical('groomer'))

    {{--
        The length of the longest appointment on the seeded list, as a phrase.
        Guarded, because a vertical with no services has no longest one and this
        page must render short rather than 500 if that row ever goes missing.
    --}}
    @php($longest = $groomer->hasPriceList() ? $groomer->slotMinutes().' minutes' : 'the length it takes')

    @include('marketing.partials.vertical-page', [
        'vertical' => $groomer,

        'copy' => [
            'headline' => 'Saturday\'s cancellation, sold twice.',
            'sub' => 'A dog drops out of your Saturday. Everybody waiting for that service, on '
                .'that day, gets a text, and the hour goes to whoever answers first instead of '
                .'sitting empty in the wash bay.',

            'diary' => [
                'day' => 'Saturday, 6 September',
                'status' => '1 slot reclaimed today',
                'caption' => 'A Saturday in a two-groomer salon. The 10:30 was cancelled at 9:14 '
                    .'and taken at 9:18.',
                'rows' => [
                    ['time' => '9:00', 'name' => 'Bella', 'service' => 'Full groom, cocker spaniel'],
                    ['time' => '10:30', 'name' => 'Max', 'service' => 'Full groom, labradoodle', 'state' => 'reclaimed', 'tag' => 'Waitlist · filled in 4 min'],
                    ['time' => '12:00', 'name' => 'Coco', 'service' => 'Bath and blow dry'],
                    ['time' => '12:45', 'name' => 'Open — waitlist notified', 'service' => '', 'state' => 'open'],
                ],
            ],

            'scenarioHeading' => 'Three Saturdays you have already had.',
            'scenarios' => [
                [
                    'label' => 'The night before',
                    'body' => 'It is nine at night and a full groom cancels for the morning. '
                        .'<b>The text goes out while you are still reading the message.</b> By '
                        .'the time you are up, the slot has a name on it.',
                ],
                [
                    'label' => 'A puppy\'s first visit',
                    'body' => 'First time in, nervous, needs longer than the price list says. '
                        .'<b>You set the length on the appointment, not on the service.</b> The '
                        .'owner books online and still gets the hour you actually need.',
                ],
                [
                    'label' => 'A doodle in a spaniel slot',
                    'body' => 'The breeds do not take the same time and the diary knows it. '
                        .'<b>Each service carries its own length, so a long groom never lands '
                        .'in a short gap.</b>',
                ],
            ],

            'sumHeading' => 'One refilled groom pays for the month.',
            'sumLede' => 'Not a projection. Two numbers you can check, one taken away from the '
                .'other.',
            'sumCaption' => 'One refilled appointment, against one month of software',

            'priceHeading' => 'You do not start from an empty diary.',
            'priceLede' => 'This is the grooming price list already in there the first time you '
                .'sign in. Change any of it, or none of it.',
            'priceCaption' => 'The grooming price list we set you up with',
            'priceFootnote' => 'The deposit comes off the bill on the day. The nail clip takes '
                .'none, because it is fifteen minutes and asking for a deposit on it would be '
                .'silly.',

            'textHeading' => 'Who gets the text.',
            'textLede' => 'Not the whole waitlist. The ones who wanted that service, on a day '
                .'they said they could do.',
            'facts' => [
                [
                    'dt' => 'The same service, not merely the same salon.',
                    'dd' => 'A cancelled full groom does not text somebody waiting for a nail '
                        .'clip. The hour that came free is '.$longest.' long, and it goes to '
                        .'somebody who needs '.$longest.'.',
                ],
                [
                    'dt' => 'On a day and at a time they can actually make.',
                    'dd' => 'When somebody joins the waitlist they say which days suit and '
                        .'whether they want mornings or afternoons. A Tuesday morning slot does '
                        .'not text the client who can only do weekends.',
                ],
                [
                    'dt' => $figures->offerBatch().' at a time, for '.$figures->offerMinutes().' minutes.',
                    'dd' => 'Then the next '.$figures->offerBatch().', if the first round goes '
                        .'quiet. Texting one person and waiting is how a Saturday morning slot '
                        .'stays empty until Saturday.',
                ],
            ],

            'subjectHeading' => 'It knows the dog, not just the booking.',
            'subjectLede' => 'A grooming diary that records only a name and a time is a calendar '
                .'with your logo on it.',
            'subjectBody' => [
                'The temperament note is the one that earns its place. <b>"Nervous with '
                    .'clippers" sits on the appointment, on the day, next to the time</b>, so '
                    .'whoever is on the table at 10:30 knows before the dog is on it.',
                'Same for the coat. A matted double coat is not the same job as a trim, and the '
                    .'note is on the booking rather than in somebody\'s head.',
            ],

            'questionHeading' => 'Questions from groomers.',
            'questionLede' => 'The setup ones, mostly.',
            'questions' => [
                [
                    'q' => 'What about the book I already have?',
                    'a' => 'We import names, dogs and the next fortnight from a spreadsheet '
                        .'before your booking page goes live. For the first ten salons we will '
                        .'come and do it with you.',
                ],
                [
                    'q' => 'What if owners are not on their phones?',
                    'a' => 'They open a link. There is no account to make and no app to install. '
                        .'A text with a link is the whole interface, which is also why the '
                        .'waitlist works at all.',
                ],
                [
                    'q' => 'Two of us work Saturdays. Does it handle that?',
                    'a' => 'Yes. Services are assigned to whoever can do them, and a freed hour '
                        .'is offered against the groomer whose hour it was. A cancellation in '
                        .'your column does not text people about a slot in somebody else\'s.',
                ],
                [
                    'q' => 'Will asking for a deposit lose me clients?',
                    'a' => 'The ones who vanish on a Saturday might. The hold comes off the bill '
                        .'on the day, and the owners who want the 9am slot are not the ones who '
                        .'object to it.',
                ],
                [
                    'q' => 'Do I have to take deposits on everything?',
                    'a' => 'No. It is set per service, and you can set it to nothing. The seeded '
                        .'list already does that on the nail clip.',
                ],
            ],

            'proofHeading' => 'From groomers using it',

            'ctaHeading' => 'Put your own Saturday in it.',
            'ctaNote' => 'Based near East Kilbride? We will come to the salon and set it up with '
                .'you.',
        ],
    ])

@endsection
