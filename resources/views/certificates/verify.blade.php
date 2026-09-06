{{-- resources/views/certificates/verify.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $valid ? 'Verified Certificate' : 'Certificate Not Found' }} &middot; Custospark Academy</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif;
            background: #f1f5f9;
            color: #0b2a55;
            line-height: 1.5;
        }

        .wrap { max-width: 680px; margin: 0 auto; padding: 40px 16px 60px; }

        .card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(2, 32, 71, 0.08);
            overflow: hidden;
        }

        .head {
            text-align: center;
            padding: 26px 24px 18px;
            border-bottom: 1px solid #ecf1f7;
        }
        .brand-img { width: 84px; }
        .brand-fallback { font-size: 20px; font-weight: 800; letter-spacing: 0.5px; }
        .institution { font-size: 17px; font-weight: 800; margin-top: 4px; }
        .institution-sub { font-size: 10.5px; letter-spacing: 2px; text-transform: uppercase; color: #8a7430; font-weight: 700; margin-top: 2px; }

        .badge-row { text-align: center; padding: 22px 24px 6px; }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            border-radius: 999px;
            padding: 9px 20px;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .badge.valid { background: #e7f6ec; color: #166534; border: 1px solid #bbe8c7; }
        .badge.invalid { background: #fdf2f2; color: #991b1b; border: 1px solid #f5c6c6; }
        .badge .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .badge.valid .dot { background: #16a34a; }
        .badge.invalid .dot { background: #dc2626; }

        .verdict { text-align: center; font-size: 13px; color: #475569; padding: 6px 24px 14px; }

        .body { padding: 8px 28px 28px; }
        .reftag {
            text-align: center;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            color: #475569;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 4px 0 20px;
            word-break: break-all;
        }

        .details { border-top: 1px solid #ecf1f7; }
        .detail {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 4px;
            border-bottom: 1px solid #f1f5f9;
        }
        .detail .k { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #8a7430; font-weight: 700; }
        .detail .v { font-size: 14px; font-weight: 700; text-align: right; }
        .detail .v.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight: 600; }

        .actions { display: flex; gap: 12px; margin-top: 22px; flex-wrap: wrap; }
        .btn {
            flex: 1;
            display: inline-block;
            text-align: center;
            text-decoration: none;
            border-radius: 10px;
            padding: 12px 18px;
            font-size: 14px;
            font-weight: 700;
            border: 1px solid transparent;
        }
        .btn.primary { background: #f86803; color: #fff; }
        .btn.primary:hover { background: #e05f02; }
        .btn.secondary { background: #ffffff; color: #0b2a55; border-color: #cbd5e1; }
        .btn.secondary:hover { background: #f8fafc; }

        .footnote {
            text-align: center;
            font-size: 12px;
            color: #64748b;
            margin-top: 18px;
            padding: 0 12px;
        }
        .footnote .check { font-weight: 700; color: #0b2a55; }

        .footer {
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="head">
                @if ($logoDataUri)
                    <img class="brand-img" src="{{ $logoDataUri }}" alt="Custospark Academy">
                @else
                    <div class="brand-fallback">Custospark Academy</div>
                @endif
                <div class="institution">Custospark Academy</div>
                <div class="institution-sub">An Institution of Custospark Company Ltd</div>
            </div>

            <div class="badge-row">
                @if ($valid)
                    <span class="badge valid"><span class="dot"></span>Verified Certificate</span>
                @else
                    <span class="badge invalid"><span class="dot"></span>Unable to Confirm</span>
                @endif
            </div>

            <div class="verdict">
                @if ($valid)
                    This certificate was issued by the Academy Registry and is authentic.
                @else
                    No certificate matching this reference exists in the Academy Registry.
                @endif
            </div>

            <div class="body">
                <div class="reftag">Reference: {{ $reference }}</div>

                @if ($valid)
                    <div class="details">
                        <div class="detail"><span class="k">Learner</span><span class="v">{{ $learner?->name ?? '—' }}</span></div>
                        <div class="detail"><span class="k">Course</span><span class="v">{{ $courseTitle ?? '—' }}</span></div>
                        <div class="detail"><span class="k">Awarded</span><span class="v">{{ $issuedAt ? $issuedAt->format('j F Y') : '—' }}</span></div>
                        <div class="detail"><span class="k">Issuer</span><span class="v">Custospark Academy &middot; Kampala, Uganda</span></div>
                    </div>

                    <div class="actions">
                        <a class="btn primary" href="{{ route('certificates.verify.pdf', $reference) }}" target="_blank">View certificate (PDF)</a>
                        <a class="btn secondary" href="{{ config('app.frontend_url') }}" rel="noopener">Visit Custospark Academy</a>
                    </div>
                @else
                    <div class="actions">
                        <a class="btn secondary" href="{{ config('app.frontend_url') }}" rel="noopener">Visit Custospark Academy</a>
                    </div>
                @endif
            </div>

            <div class="footnote">
                Checked against the Academy Registry at
                <span class="check">{{ $checkedAt->setTimezone('Africa/Kampala')->format('j F Y, H:i T') }}</span>
            </div>
        </div>

        <div class="footer">Custospark Company Ltd &middot; Kampala, Uganda</div>
    </div>
</body>
</html>