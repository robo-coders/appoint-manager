@php
    $ink = \App\Support\DesignTokens::value('ink');
    $paper = \App\Support\DesignTokens::value('paper');
    $accent = \App\Support\DesignTokens::value('accent');
    $muted = \App\Support\DesignTokens::value('ink-2');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 36px 40px; }
        body {
            margin: 0;
            background: {{ $paper }};
            color: {{ $ink }};
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            line-height: 1.5;
        }
        .mono { font-family: DejaVu Sans Mono, monospace; }
        .header {
            border: 1px solid {{ $ink }};
            border-radius: 6px;
            padding: 18px 20px;
            margin-bottom: 28px;
        }
        .mark { color: {{ $accent }}; font-size: 18px; letter-spacing: -0.02em; }
        .number { color: {{ $accent }}; font-family: DejaVu Sans Mono, monospace; font-size: 13px; }
        h1 { font-weight: normal; font-size: 22px; margin: 8px 0 0; }
        .muted { color: {{ $muted }}; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th {
            text-align: left;
            font-size: 10px;
            color: {{ $muted }};
            font-weight: normal;
            border-bottom: 1px solid {{ $ink }};
            padding: 6px 0;
        }
        td { padding: 10px 0; border-bottom: 1px solid {{ $ink }}; }
        .right { text-align: right; }
        .footer { margin-top: 36px; font-size: 10px; color: {{ $muted }}; }
    </style>
</head>
<body>
    <div class="header">
        <div class="mark">{{ $product }}</div>
        <h1>Invoice</h1>
        <p class="number">{{ $receipt->invoice_number }}</p>
    </div>

    <p>
        <strong>{{ $tenant?->name }}</strong><br>
        @if ($tenant?->address_line_1) {{ $tenant->address_line_1 }}<br> @endif
        @if ($tenant?->address_line_2) {{ $tenant->address_line_2 }}<br> @endif
        {{ trim(($tenant?->city ?? '').' '.($tenant?->postcode ?? '')) }}
    </p>

    <p class="mono muted">Issued {{ $receipt->issued_at?->format('j M Y') }}</p>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $plan_name }}</td>
                <td class="right mono">{{ $amount }}</td>
            </tr>
            @if ($vat_number)
            <tr>
                <td>VAT ({{ $vat_number }})</td>
                <td class="right mono">—</td>
            </tr>
            @endif
            <tr>
                <td><strong>Total</strong></td>
                <td class="right mono"><strong>{{ $amount }}</strong></td>
            </tr>
        </tbody>
    </table>

    @if ($card)
        <p class="muted">Paid with {{ $card }}</p>
    @endif

    <p class="footer">
        {{ $seller['legal_name'] }}
        @if ($seller['address']) · {{ $seller['address'] }} @endif
        @if ($seller['company_number']) · Company {{ $seller['company_number'] }} @endif
        @if ($seller['vat_number']) · VAT {{ $seller['vat_number'] }} @endif
    </p>
</body>
</html>
