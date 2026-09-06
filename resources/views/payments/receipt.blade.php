{{-- resources/views/payments/receipt.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            font-family: 'DejaVu Sans', -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif;
            color: #1e293b;
            font-size: 13px;
            line-height: 1.5;
        }

        .page {
            border: 2px solid #0b2a55;
            border-radius: 10px;
            padding: 34px 38px;
            position: relative;
        }

        .accent-bar {
            height: 6px;
            background: linear-gradient(90deg, #008bfa 0%, #0e5aa8 45%, #f86803 100%);
            border-radius: 4px;
            margin-bottom: 26px;
        }

        .header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .header td { padding: 0; vertical-align: middle; }
        .brand-block { border-collapse: collapse; }
        .brand-block td { vertical-align: middle; }
        .brand-logo { width: 84px; }

        .brand-name {
            font-size: 26px;
            font-weight: 700;
            color: #0b2a55;
            letter-spacing: -0.5px;
        }
        .brand-slogan {
            font-size: 11px;
            color: #f86803;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-top: 2px;
        }
        .brand-parent {
            font-size: 10px;
            color: #64748b;
            font-style: italic;
            margin-top: 3px;
        }

        .doc-title {
            text-align: right;
            font-size: 20px;
            font-weight: 700;
            color: #0b2a55;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .doc-sub {
            text-align: right;
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        .meta-box {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 14px 16px;
            margin-bottom: 24px;
            background: #f8fafc;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
        }
        .meta td { padding: 4px 0; vertical-align: top; }
        .meta-label { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-value { font-weight: 600; color: #0b2a55; }
        .meta-value.mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 12px; }

        h3.section {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #008bfa;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 6px;
            margin: 0 0 12px;
        }

        .items { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        .items th {
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            border-bottom: 2px solid #0b2a55;
            padding: 6px 8px;
        }
        .items td {
            padding: 9px 8px;
            border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
        }
        .items tr:last-child td { border-bottom: 0; }
        .items .num { text-align: right; }
        .fee-label { font-weight: 600; color: #0b2a55; }
        .course-name { color: #475569; }

        .totals { width: 280px; float: right; border-collapse: collapse; margin-bottom: 26px; }
        .totals td { padding: 7px 8px; }
        .totals .amount { text-align: right; font-weight: 700; color: #0b2a55; }
        .totals .grand-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #475569; }
        .totals .grand-amount {
            font-size: 17px;
            font-weight: 700;
            color: #0b2a55;
        }
        .totals tr.grand td {
            border-top: 2px solid #0b2a55;
            border-bottom: 2px solid #0b2a55;
        }
        .paid-badge {
            text-align: right;
            font-size: 11px;
            font-weight: 700;
            color: #15803d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
            font-size: 11px;
            color: #64748b;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer td { vertical-align: top; padding: 0 12px 0 0; }
        .footer .head { font-weight: 700; color: #0b2a55; margin-bottom: 4px; }
        .footer a { color: #008bfa; text-decoration: none; }

        .clearfix::after { content: ""; display: table; clear: both; }
    </style>
</head>
<body>
    <div class="page">
        <div class="accent-bar"></div>

        <table class="header">
            <tr>
                <td>
                    <table class="brand-block">
                        <tr>
                            @if ($logoDataUri)
                                <td style="padding-right:16px;">
                                    <img class="brand-logo" src="{{ $logoDataUri }}" alt="">
                                </td>
                            @endif
                            <td>
                                <div class="brand-name">Custospark Academy</div>
                                <div class="brand-slogan">Learn. Build. Launch.</div>
                                <div class="brand-parent">A product of {{ $company['name'] }} - {{ $company['city'] }}, {{ $company['country'] }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
                <td>
                    <div class="doc-title">Receipt</div>
                    <div class="doc-sub">Tax invoice for tuition services</div>
                </td>
            </tr>
        </table>

        <div class="meta-box">
            <table class="meta">
                <tr>
                    <td class="meta-label">Invoice no.</td>
                    <td class="meta-value mono">{{ $invoiceNumber }}</td>
                    <td class="meta-label">Payment date</td>
                    <td class="meta-value">{{ $paidAt ? $paidAt->format('j F Y') : '-' }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Learner</td>
                    <td class="meta-value">{{ $student?->name ?? 'Academy learner' }}</td>
                    <td class="meta-label">Reference</td>
                    <td class="meta-value mono">{{ $reference ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="meta-label">Payer email</td>
                    <td class="meta-value">{{ $student?->email ?? '-' }}</td>
                    <td class="meta-label">Method</td>
                    <td class="meta-value capitalize">{{ str_replace('_', ' ', ucfirst($method)) }}</td>
                </tr>
            </table>
        </div>

        <h3 class="section">Charge</h3>
        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="fee-label">{{ ucfirst($feeLabel) }}</div>
                        <div class="course-name">{{ $courseTitle }}</div>
                    </td>
                    <td class="num">{{ number_format($amount, 0, '.', ',') }} {{ $currency }}</td>
                </tr>
            </tbody>
        </table>

        <div class="clearfix">
            <table class="totals">
                <tr class="grand">
                    <td class="grand-label">Amount paid</td>
                    <td class="grand-amount num">{{ number_format($amount, 0, '.', ',') }} {{ $currency }}</td>
                </tr>
                <tr>
                    <td colspan="2" class="paid-badge">&check; Paid in full</td>
                </tr>
            </table>
        </div>

        <div class="footer">
            <table>
                <tr>
                    <td>
                        <div class="head">{{ $company['name'] }}</div>
                        {{ $company['city'] }}, {{ $company['country'] }}<br>
                        {{ $company['email'] }}<br>
                        {{ $company['website'] }}
                    </td>
                    <td style="text-align:right;">
                        <div class="head">Questions about this receipt?</div>
                        Reply to this email or contact {{ $company['email'] }}.<br>
                        Keep this receipt for your records.
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>