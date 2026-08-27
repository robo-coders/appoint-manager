{{--
    One ink button, built the way Outlook needs one.

    A `<a>` with padding is not a button in Word's engine — the background paints
    behind the text only and the padding is dropped — so the fill is a table cell
    and the link fills it. `mso-padding-alt` and the VML fallback are what make
    the shape survive; every other client ignores both.
--}}
@php
    $ink = App\Support\DesignTokens::value('ink');
    $white = App\Support\DesignTokens::value('white');
    $sans = 'Geist, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td class="action" bgcolor="{{ $ink }}" style="background-color:{{ $ink }};border-radius:6px;mso-padding-alt:14px 24px;">
            {{--
                `class="action-label"` as well as the cell's `class="action"`,
                and it is load-bearing. The dark-mode block swaps the fill to
                light and the *cell's* colour to ink, but the anchor carries its
                own inline `color` — which wins over a class on its parent, so
                the label stayed white on a near-white button and the only
                action in the message was invisible. Found by rendering it in a
                dark-scheme browser and looking, which is the only way to find
                it.
            --}}
            <a href="{{ $url }}" class="action-label"
               style="display:inline-block;padding:14px 24px;font-family:{{ $sans }};font-size:14px;font-weight:500;line-height:1;color:{{ $white }};text-decoration:none;border-radius:6px;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>
