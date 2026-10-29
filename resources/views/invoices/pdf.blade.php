@use('App\Support\Money')
@php
    $cur = $invoice->currency;
    $client = $invoice->project->client;
    $logo = $settings->logoImage(230, 64);
    $watermark = $settings->watermarkImage(420, 420);

    $gold = '#F5A915';
    $icons = [
        'address' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="'.$gold.'" d="M12 1C7.6 1 4 4.6 4 9c0 6 8 14 8 14s8-8 8-14c0-4.4-3.6-8-8-8zm0 11a3 3 0 1 1 0-6 3 3 0 0 1 0 6z"/></svg>',
        'phone' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="'.$gold.'" d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg>',
        'email' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" fill="'.$gold.'"/><path d="M2 6l10 7 10-7" stroke="#ffffff" stroke-width="2" fill="none"/></svg>',
        'website' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="11" fill="'.$gold.'"/><ellipse cx="12" cy="12" rx="4.5" ry="10" stroke="#ffffff" stroke-width="1.6" fill="none"/><path d="M1.5 12h21M3.5 6.5h17M3.5 17.5h17" stroke="#ffffff" stroke-width="1.6" fill="none"/></svg>',
    ];
    $footerItems = collect([
        'address' => $settings->address ? preg_replace('/\s*\R\s*/', ', ', trim($settings->address)) : null,
        'phone' => $settings->phone,
        'email' => $settings->email,
        'website' => $settings->website,
    ])->filter();

    // Pad short invoices with empty table space so they resemble the sample's spacious layout.
    $usedHeight = $invoice->items->sum(fn ($item) => 26 + 13 * max(0, (int) ceil(mb_strlen($item->description) / 52) + substr_count($item->description, "\n") - 1));
    $usedHeight += $invoice->payments->isNotEmpty() ? 52 + 18 * $invoice->payments->count() : 0;
    $usedHeight += $invoice->notes ? 46 + 13 * (int) ceil(mb_strlen($invoice->notes) / 95) + 13 * substr_count($invoice->notes, "\n") : 0;
    $fillerHeight = max(0, 255 - $usedHeight);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        @page { margin: 34pt 40pt 78pt 40pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #2b2b2b; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .gold { color: {{ $gold }}; }
        .muted { color: #6f6f6f; }

        .watermark { position: fixed; top: 330pt; left: 150pt; width: 420pt; text-align: center; opacity: 0.08; }

        .footer { position: fixed; bottom: -56pt; left: 0; right: 0; height: 40pt; }
        .footer-line td { height: 0; padding: 0; }
        .footer-items { margin-top: 12pt; }
        .footer-items td { font-size: 8pt; color: #3a3a3a; white-space: nowrap; vertical-align: middle; padding: 0 4pt; }
        .footer-items img { width: 10pt; height: 10pt; vertical-align: middle; }

        .header td { vertical-align: middle; }
        .brand-name { font-size: 20pt; font-weight: bold; color: #333333; letter-spacing: .5pt; }
        .date-line { font-size: 9.5pt; font-weight: bold; white-space: nowrap; }
        .invoice-no { font-size: 8.5pt; color: #6f6f6f; margin-top: 3pt; text-align: right; white-space: nowrap; }

        .title { text-align: center; font-size: 26pt; font-weight: bold; color: #262626; margin: 30pt 0 24pt; }

        .info-box { border: 1pt solid {{ $gold }}; border-radius: 7pt; padding: 13pt 16pt; }
        .info-box td { width: 33.33%; padding: 0 14pt; }
        .info-box td.sep { border-left: 0.8pt solid #c9c9c9; }
        .info-label { font-size: 9pt; color: #555555; margin-bottom: 3pt; }
        .info-value { font-size: 11.5pt; font-weight: bold; color: #222222; }

        .details { margin: 9pt 0 18pt; font-size: 8pt; color: #555555; }
        .details td { padding: 0 2pt; }

        .items { border-collapse: separate; border-spacing: 0; border: 0.9pt solid #4a4a4a; border-top: none; border-radius: 7pt; }
        .items th { border-top: 0.9pt solid #4a4a4a; }
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }
        .items th { background: #FCD98C; font-weight: bold; font-size: 9.5pt; padding: 9pt 8pt; text-align: center; border-bottom: 0.6pt solid #d9d9d9; }
        .items th.first { border-top-left-radius: 6pt; }
        .items th.last { border-top-right-radius: 6pt; }
        .items td { padding: 9pt 8pt; border-bottom: 0.6pt solid #e2e2e2; text-align: center; }
        .items .sep { border-left: 0.6pt solid #d9d9d9; }
        .items td.desc { text-align: left; font-weight: bold; white-space: pre-line; }
        .items th.desc { text-align: left; }
        .items td.money { text-align: right; white-space: nowrap; }
        .items td.filler { border-bottom: none; padding: 0; }
        .items .total td { background: #FCD98C; font-weight: bold; font-size: 11.5pt; padding: 9pt 14pt; border-bottom: none; }
        .items .total td.label { text-align: left; border-bottom-left-radius: 6pt; }
        .items .total td.amount { text-align: right; border-bottom-right-radius: 6pt; }

        .summary { margin-top: 12pt; page-break-inside: avoid; }
        .summary td { vertical-align: middle; }
        .stamp { display: inline-block; font-size: 25pt; font-weight: bold; letter-spacing: 4pt; padding: 3pt 14pt; border: 3.5pt solid; border-radius: 7pt; transform: rotate(-8deg); }
        .stamp-due { color: #c62828; border-color: #c62828; }
        .stamp-paid { color: #1b7f4b; border-color: #1b7f4b; }
        .stamp-partial { color: #c25e00; border-color: #c25e00; font-size: 17pt; letter-spacing: 2pt; padding: 5pt 12pt; }
        .balances td { padding: 3pt 14pt; font-size: 9.5pt; }
        .balances .due td { font-weight: bold; font-size: 10.5pt; border-top: 0.6pt solid #d9d9d9; padding-top: 5pt; }

        .section { margin-top: 16pt; page-break-inside: avoid; }
        .section-title { font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: .8pt; color: #8a6400; margin-bottom: 4pt; }
        .payments th { text-align: left; font-size: 8pt; color: #6f6f6f; font-weight: normal; border-bottom: 0.6pt solid #e2e2e2; padding: 3pt 6pt; }
        .payments td { font-size: 8.5pt; padding: 3pt 6pt; }
        .payments .r { text-align: right; }
        .notes { white-space: pre-line; font-size: 9pt; }
    </style>
</head>
<body>
    @if ($watermark)
        <div class="watermark"><img src="{{ $watermark['src'] }}" width="{{ $watermark['width'] }}" height="{{ $watermark['height'] }}" alt=""></div>
    @endif

    <div class="footer">
        <table class="footer-line">
            <tr>
                <td style="width:13%; border-top:2pt solid {{ $gold }}"></td>
                <td style="border-top:0.6pt solid #d0d0d0"></td>
                <td style="width:13%; border-top:2pt solid {{ $gold }}"></td>
            </tr>
        </table>
        @if ($footerItems->isNotEmpty())
            <table class="footer-items">
                <tr>
                    @foreach ($footerItems as $type => $text)
                        <td style="text-align:{{ $loop->first ? 'left' : ($loop->last ? 'right' : 'center') }}">
                            <img src="data:image/svg+xml;base64,{{ base64_encode($icons[$type]) }}" alt=""> {{ $text }}
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>

    <table class="header">
        <tr>
            <td style="width:45%">
                @if ($logo)
                    <img src="{{ $logo['src'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $settings->name }}">
                @else
                    <div class="brand-name">{{ $settings->name }}</div>
                @endif
            </td>
            <td>
                <table>
                    <tr>
                        <td style="vertical-align:middle; padding-right:12pt">
                            <table>
                                <tr>
                                    <td style="border-top:0.6pt solid #d0d0d0"></td>
                                    <td style="width:30%; border-top:2pt solid {{ $gold }}"></td>
                                </tr>
                            </table>
                        </td>
                        <td style="width:1%; vertical-align:middle">
                            <div class="date-line">Date: {{ $invoice->issued_on->format('d / m / Y') }}</div>
                        </td>
                    </tr>
                </table>
                <div class="invoice-no">
                    Invoice No: <strong style="color:#2b2b2b">{{ $invoice->number }}</strong>
                    @if ($invoice->due_on) &nbsp;·&nbsp; Due: {{ $invoice->due_on->format('d / m / Y') }} @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="title">Payment <span class="gold">Invoice</span></div>

    <div class="info-box">
        <table>
            <tr>
                <td style="padding-left:0">
                    <div class="info-label">Beneficiary Name</div>
                    <div class="info-value">{{ $client->name }}</div>
                </td>
                <td class="sep">
                    <div class="info-label">Payment Method</div>
                    <div class="info-value">{{ $invoice->paymentMethodLabel() }}</div>
                </td>
                <td class="sep">
                    <div class="info-label">Purpose</div>
                    <div class="info-value">{{ $invoice->purposeLabel() }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="details">
        <tr>
            <td>
                <span class="muted">Client:</span>
                {{ collect([$client->mobile, $client->mobile_alt, $client->email, $client->address ? preg_replace('/\s*\R\s*/', ', ', trim($client->address)) : null, $client->country])->filter()->implode(' · ') }}
            </td>
            <td style="text-align:right; white-space:nowrap">
                <span class="muted">Project:</span> {{ $invoice->project->name }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                @php($thSep = 'border-left:0.6pt solid #e6c06a;')
                <th class="first" style="width:7%">SL</th>
                <th class="desc" style="width:43%; {{ $thSep }}">Product / Service Details</th>
                <th style="width:13%; {{ $thSep }}">Qty</th>
                <th style="width:17%; {{ $thSep }}">Unit Price</th>
                <th class="last" style="width:20%; {{ $thSep }}">Total Price</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="desc sep">{{ $item->description }}</td>
                    <td class="sep">{{ $item->displayQuantityWithUnit() }}</td>
                    <td class="money sep">{{ Money::format($item->unit_price) }}</td>
                    <td class="money sep">{{ Money::format($item->amount) }}</td>
                </tr>
            @endforeach
            @if ($fillerHeight > 0)
                <tr><td colspan="5" class="filler" style="height:{{ $fillerHeight }}pt"></td></tr>
            @endif
            <tr class="total">
                <td colspan="3" class="label">Total Amount</td>
                <td colspan="2" class="amount">{{ Money::format($invoice->total, $cur) }} /-</td>
            </tr>
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td style="width:50%; padding:10pt 0 6pt 22pt">
                <span class="stamp {{ $invoice->status->stampClass() }}">{{ $invoice->status->stamp() }}</span>
            </td>
            <td>
                <table class="balances">
                    <tr><td>Amount paid</td><td style="text-align:right">{{ Money::format($invoice->amount_paid, $cur) }}</td></tr>
                    <tr class="due"><td>Balance due</td><td style="text-align:right">{{ Money::format($invoice->balance(), $cur) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($invoice->payments->isNotEmpty())
        <div class="section">
            <div class="section-title">Payment history</div>
            <table class="payments">
                <tr><th>Date</th><th>Method</th><th>Reference</th><th class="r">Amount</th></tr>
                @foreach ($invoice->payments as $payment)
                    <tr>
                        <td>{{ $payment->paid_on->format('d / m / Y') }}</td>
                        <td>{{ $payment->method->label() }}</td>
                        <td>{{ $payment->reference ?? '—' }}</td>
                        <td class="r">{{ Money::format($payment->amount, $cur) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($invoice->notes)
        <div class="section">
            <div class="section-title">Notes</div>
            <div class="notes">{{ $invoice->notes }}</div>
        </div>
    @endif
</body>
</html>
