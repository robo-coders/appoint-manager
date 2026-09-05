{{--
    A contact-form enquiry, to us.

    The message is the only string in this tree a stranger wrote. It is printed
    with `{{ }}`, escaped, and `nl2br` is applied to the *escaped* result rather
    than the raw one — `nl2br(e($body))` and never `e(nl2br($body))`, which
    would escape the `<br>` tags it had just inserted and print them.
--}}
<x-mail-layout :subject="$subject" :heading="$heading" :lede="$lede" :preheader="$preheader" :footer="$footer">
    @include('mail.parts.rows', ['rows' => $rows])

    @php
        $ink = App\Support\DesignTokens::value('ink');
        $sans = 'Geist, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    @endphp
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr><td class="ink" style="padding:24px 0 0;font-family:{{ $sans }};font-size:15px;line-height:1.6;color:{{ $ink }};">
            {!! nl2br(e($body)) !!}
        </td></tr>
    </table>
</x-mail-layout>
