{{-- resources/views/certificates/certificate.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            padding: 0;
            font-family: 'DejaVu Sans', -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif;
            color: #0b2a55;
            background: #ffffff;
        }

        .sheet { position: relative; width: 100%; height: 100%; }

        /* Single elegant frame with a thin mat rule - reads as one border,
           not stacked frames. */
        .frame {
            position: absolute;
            top: 18px; left: 18px; right: 18px; bottom: 18px;
            border: 2.5px solid #b8902e;
            border-radius: 16px;
        }
        .frame-inner {
            position: absolute;
            top: 7px; left: 7px; right: 7px; bottom: 7px;
            border: 0.8px solid #d9c070;
            border-radius: 9px;
        }
        .corner {
            position: absolute;
            width: 24px; height: 24px;
            border: 3px solid #0b2a55;
        }
        .corner.tl { top: 10px; left: 10px; border-right: 0; border-bottom: 0; border-top-left-radius: 6px; }
        .corner.tr { top: 10px; right: 10px; border-left: 0; border-bottom: 0; border-top-right-radius: 6px; }
        .corner.bl { bottom: 10px; left: 10px; border-right: 0; border-top: 0; border-bottom-left-radius: 6px; }
        .corner.br { bottom: 10px; right: 10px; border-left: 0; border-top: 0; border-bottom-right-radius: 6px; }

        .content { padding: 36px 84px 26px; text-align: center; }

        .brand { margin-bottom: 10px; }
        .brand-img { width: 140px; }
        .brand-fallback {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 1px;
            color: #0b2a55;
        }

        .institution {
            font-family: 'DejaVu Serif', Georgia, 'Times New Roman', serif;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: 0.4px;
            color: #0b2a55;
            margin-top: 6px;
        }
        .institution-sub {
            font-size: 10px;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: #8a7430;
            font-weight: 700;
            margin-top: 2px;
        }

        .rule { width: 520px; border: 0; border-top: 1.2px solid #e3d49a; margin: 12px auto 10px; }

        .doc-title {
            font-size: 22px;
            letter-spacing: 6px;
            text-transform: uppercase;
            color: #0b2a55;
            font-weight: 700;
        }

        .recital { font-size: 12px; color: #64748b; margin-top: 16px; letter-spacing: 0.4px; }

        .learner-name {
            font-family: 'DejaVu Serif', Georgia, 'Times New Roman', serif;
            font-size: 42px;
            font-weight: 700;
            line-height: 1.2;
            margin-top: 6px;
        }
        .name-rule { width: 360px; border: 0; border-bottom: 1.2px solid #b8902e; margin: 3px auto 0; }

        .has-completed { font-size: 12px; color: #64748b; margin-top: 10px; }

        .course {
            font-size: 28px;
            font-weight: 700;
            color: #f86803;
            line-height: 1.2;
            margin-top: 4px;
        }

        .fact-strip {
            width: 460px;
            margin: 16px auto 0;
            background: #f7f4ea;
            border: 1px solid #e3d49a;
            border-radius: 10px;
            padding: 10px 8px;
        }
        .fact { width: 100%; border-collapse: collapse; }
        .fact td { text-align: center; padding: 0 14px; vertical-align: top; }
        .fact .sep { width: 1px; padding: 0; border-left: 1px solid #e3d49a; }
        .hint { font-size: 9px; text-transform: uppercase; letter-spacing: 1.2px; color: #8a7430; }
        .value { font-size: 13px; font-weight: 700; color: #0b2a55; margin-top: 2px; }
        .value.mono { font-family: 'DejaVu Sans Mono', monospace; }

        .foot {
            width: 100%;
            margin-top: 12px;
            border-collapse: collapse;
        }
        .foot td { vertical-align: middle; }
        .foot .left { text-align: left; width: 60%; }
        .foot .right { text-align: right; width: 40%; }

        .qr-wrap { display: inline-block; text-align: center; }
        .qr-img { width: 72px; height: 72px; }
        .qr-caption {
            font-size: 8px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #64748b;
            margin-top: 3px;
        }

        .issued-by { font-size: 11px; color: #475569; line-height: 1.5; }
        .issued-by .who { font-weight: 700; color: #0b2a55; font-size: 12px; }
        .verify-hint { font-size: 11px; color: #475569; margin-top: 4px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="frame"><div class="frame-inner"></div></div>
        <div class="corner tl"></div>
        <div class="corner tr"></div>
        <div class="corner bl"></div>
        <div class="corner br"></div>

        <div class="content">
            <div class="brand">
                @if ($logoDataUri)
                    <img class="brand-img" src="{{ $logoDataUri }}" alt="Custospark Academy">
                @else
                    <div class="brand-fallback">Custospark Academy</div>
                @endif
                <div class="institution">Custospark Academy</div>
                <div class="institution-sub">An Institution of Custospark Company Ltd</div>
            </div>

            <hr class="rule">

            <div class="doc-title">Certificate of Completion</div>
            <div class="recital">This certifies that</div>
            <div class="learner-name">{{ $student?->name ?? 'Learner' }}</div>
            <div class="name-rule"></div>
            <div class="has-completed">has successfully completed the course</div>
            <div class="course">{{ $courseTitle ?? 'Course' }}</div>

            <table class="fact-strip"><tbody>
                <tr>
                    <td><div class="hint">Awarded</div><div class="value">{{ $issuedAt ? $issuedAt->format('j F Y') : '-' }}</div></td>
                    <td class="sep"></td>
                    <td><div class="hint">Reference</div><div class="value mono">{{ $reference }}</div></td>
                </tr>
            </tbody></table>

            <table class="foot">
                <tr>
                    <td class="left">
                        <div class="issued-by">
                            <span class="who">Custospark Company Ltd</span> &middot; Kampala, Uganda
                        </div>
                        <div class="verify-hint">
                            Scan the QR code or visit {{ $verifyUrl }} to verify this certificate.<br>
                            Issued by the Academy Registry &middot; Reference {{ $reference }}.
                        </div>
                    </td>
                    <td class="right">
                        <span class="qr-wrap">
                            @if ($qrDataUri)
                                <img class="qr-img" src="{{ $qrDataUri }}" alt="Verify QR">
                                <div class="qr-caption">Scan to verify</div>
                            @else
                                <div class="qr-caption">{{ $reference }}</div>
                            @endif
                        </span>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>