{{--
    The ledger. It was the home page's hero and it is the pricing page's answer
    to one question: "why do you charge me when they are free?"

    **It is no longer a cost comparison.** As a cost comparison it only won above
    roughly 32 appointments a month, so the groomer this product is for read it
    and correctly worked out that we were the expensive option. Worse, it was an
    argument a competitor could delete by changing a setting. Reframed, it is a
    positioning argument — *who* pays, not *how much* — and that survives the fee
    moving, being absorbed, or going away.

    **There is no monthly volume in it.** Direction A multiplied the per-booking
    fee by eighty appointments to reach £100. That number is what made this a
    calculator, and the calculator is what lost. The fee is stated per booking
    and the recurrence is carried by the row label, so the bill reads the same
    for a groomer doing twenty as for one doing eighty.

    **The competitor is not named**, here or anywhere on this surface.

    **The per-booking figure is unverified and marked as such**, on the line that
    prints it. Everything the copy actually argues is above the figure rather
    than downstream of it: strike £1.25 out, put any other number in, and the
    two sum rows still say what they say.

    Card processing is deliberately not a row. Ours is Stripe's own
    per-transaction fee on the salon's own Stripe account and we do not add to
    it — but we have not confirmed what anybody else does, and a row asserting
    "at cost" in both columns would be inventing the half we cannot check.
--}}
<table class="bill">
    <caption>One month of software, and the fee on one appointment</caption>
    <thead>
        <tr>
            <th scope="col"><span class="sr-only">Line</span></th>
            <th scope="col" class="fig">On a free plan</th>
            <th scope="col" class="fig">On {{ config('product.name') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <th scope="row">Software, a month</th>
            <td class="fig">£0.00</td>
            <td class="fig">{{ $figures->monthly()->formatted() }}</td>
        </tr>
        <tr>
            <th scope="row">Booking fee, charged to your client, on every appointment she books</th>
            <!-- UNVERIFIED: competitor per-booking fee, rate and plan scope not confirmed -->
            <!-- Verified by: their published pricing page, dated and archived, stating the
                 per-booking fee and which plans it applies to. Until then this is an
                 illustration of a charging model and the copy below says so. -->
            <td class="fig">£1.25</td>
            <td class="fig">£0.00</td>
        </tr>
        <tr class="sum sum-first">
            <th scope="row">You pay</th>
            <td class="fig big">£0.00</td>
            <td class="fig big">{{ $figures->monthly()->formatted() }}</td>
        </tr>
        {{--
            The bottom line of this bill is a name, not a number, and that is the
            entire reframe. A money total here would be the cost comparison that
            lost on the home page, and it would need a monthly appointment count
            to compute — the thing that made the argument a calculator. Answering
            "who" instead means the last row still reads correctly no matter what
            the unverified figure above it turns out to be, or whether it exists
            at all.
        --}}
        <tr class="sum">
            <th scope="row">Who funds it</th>
            <td class="fig words">your clients</td>
            <td class="fig words diff">you</td>
        </tr>
    </tbody>
</table>

<p class="punch">
    Their software is paid for by your clients. Ours is paid for by you. That is the whole
    difference, and it does not move when somebody changes their rate.
</p>

<p class="footnote">
    We have not confirmed that rate, or whether it applies on the paid plans or only the free one,
    so treat the figure as an illustration and not as a published price. It is also not the
    argument: strike it out and put any number you like in its place, and the two lines that matter
    still read the same way round. Card processing is Stripe's own per-transaction fee, charged to
    your own Stripe account, and we do not add anything to it.
</p>
