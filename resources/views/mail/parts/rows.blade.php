{{--
    A short table of facts: label left, value right, hairline between.

    The value column is mono and tabular, which is the one place email keeps
    faith with the app exactly — a time and an amount are numbers, and DESIGN.md
    says numbers are mono tabular everywhere. In a fallback mono face they still
    align on the decimal, which is the whole reason for the rule.

    `@param  list<array{label: string, value: string, strong?: bool}>  $rows`
--}}
@php
    $ink = App\Support\DesignTokens::value('ink');
    $ink2 = App\Support\DesignTokens::value('ink-2');
    $sans = 'Geist, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    $mono = '"Geist Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
    @foreach ($rows as $index => $row)
        <tr>
            <td class="ink-2 rule" style="padding:10px 12px 10px 0;border-top:1px solid rgba(24,23,20,0.10);font-family:{{ $sans }};font-size:13px;line-height:1.5;color:{{ $ink2 }};vertical-align:top;">
                {{ $row['label'] }}
            </td>
            <td class="ink rule" align="right" style="padding:10px 0;border-top:1px solid rgba(24,23,20,0.10);font-family:{{ ($row['mono'] ?? true) ? $mono : $sans }};font-size:13px;line-height:1.5;color:{{ $ink }};vertical-align:top;font-variant-numeric:tabular-nums;">
                {{ $row['value'] }}
            </td>
        </tr>
    @endforeach
</table>
