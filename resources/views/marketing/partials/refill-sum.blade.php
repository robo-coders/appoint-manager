{{--
    The arithmetic behind "one refilled slot covers the month", shown as working.

    **There is no monthly volume in this sum, and that is the point.** The
    previous home page led with a ledger comparing a competitor's per-booking fee
    against our £39, and that argument only wins above roughly 32 appointments a
    month — so the groomer this product is for, doing twenty, read it and
    correctly concluded we were the expensive option. This sum is true at twenty
    appointments and at eighty, because volume is not in it.

    Both figures come from `App\Support\MarketingFigures`, which reads
    `config/verticals.php` and `config/billing.php`. Neither is typed here, and
    the difference is subtracted rather than stated, so a price change moves the
    whole sum instead of leaving a stale total behind.

    The slot price is **our own seeded default**, and it is described as that
    rather than as a market average. `config/verticals.php` seeds a medium full
    groom at £45; the 2026 UK average is probably nearer £55, but that figure is
    itself unverified and this page is not the place to publish it. See
    DECISIONS.md, "Open — the seeded medium groom price".

    A table, not a description list: a label, a figure and a total is what a
    table is. As a two-column grid the total's rule had the grid gap cut out of
    the middle of it, which a screenshot at 375 showed and the markup did not.
--}}
<table class="bill">
    <caption>One refilled appointment, against one month of software</caption>
    <tbody>
        <tr>
            <th scope="row">One {{ lcfirst($figures->slotName()) }}, at the price we set you up with</th>
            <td class="fig">{{ $figures->slot()->formatted() }}</td>
        </tr>
        <tr>
            <th scope="row">{{ config('product.name') }}, one month</th>
            <td class="fig">&minus;{{ $figures->monthly()->formatted() }}</td>
        </tr>
        <tr class="sum sum-first">
            <th scope="row">
                @if ($figures->oneRefillCovers())
                    Still yours, once the month is paid for
                @else
                    Left to find, after one refilled slot
                @endif
            </th>
            <td class="fig big">{{ $figures->surplus()->formatted() }}</td>
        </tr>
    </tbody>
</table>

<p class="footnote">
    Put your own slot price in the top line. Anything at or above
    <span class="font-mono">{{ $figures->monthly()->formatted() }}</span> and one refilled
    cancellation has paid for the month — the second one is yours. It does not matter whether you
    take twenty appointments a month or eighty, because how many you take is not in the sum.
</p>
