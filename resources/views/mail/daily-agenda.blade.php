@php
    $ink = App\Support\DesignTokens::value('ink');
    $ink2 = App\Support\DesignTokens::value('ink-2');
    $sans = 'Geist, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    $mono = '"Geist Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
@endphp
<x-mail-layout :subject="$subject" :heading="$heading" :lede="$lede" :preheader="$preheader" :footer="$footer">
    {{--
        A day, as a list of times.

        Not `mail.parts.rows`: that is label-left, value-right, and this is the
        other way round — the time is the thing you scan down, so it leads and
        it is mono, and the name and service follow it. Same hairlines.
    --}}
    @if (count($agenda) === 0)
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
            <tr>
                <td class="ink-2 rule" style="padding:16px 0;border-top:1px solid rgba(24,23,20,0.10);border-bottom:1px solid rgba(24,23,20,0.10);font-family:{{ $sans }};font-size:14px;line-height:1.5;color:{{ $ink2 }};">
                    {{ $emptyLine }}
                </td>
            </tr>
        </table>
    @else
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
            @foreach ($agenda as $item)
                <tr>
                    <td class="ink rule" width="64" style="padding:10px 12px 10px 0;border-top:1px solid rgba(24,23,20,0.10);font-family:{{ $mono }};font-size:13px;line-height:1.5;color:{{ $ink }};vertical-align:top;font-variant-numeric:tabular-nums;white-space:nowrap;">
                        {{ $item['time'] }}
                    </td>
                    <td class="ink rule" style="padding:10px 0;border-top:1px solid rgba(24,23,20,0.10);font-family:{{ $sans }};font-size:14px;line-height:1.5;color:{{ $ink }};vertical-align:top;">
                        {{ $item['who'] }}<br>
                        <span class="ink-2" style="font-size:13px;color:{{ $ink2 }};">{{ $item['what'] }}</span>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr><td style="padding:24px 0 0;">
            @include('mail.parts.action', ['url' => $diaryUrl, 'label' => 'Open the diary'])
        </td></tr>
    </table>
</x-mail-layout>
