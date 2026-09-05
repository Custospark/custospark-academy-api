{{-- resources/views/emails/standard.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #06152e;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                         'Helvetica Neue', Arial, sans-serif;
            color: #c5d2e0;
        }

        .email-container {
            position: relative;
            max-width: 600px;
            margin: 40px auto;
            background-color: #06152e;
            border-radius: 10px;
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.4);
            border: 1px solid #1b405f;
            overflow: hidden;
        }

        .email-container::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #008bfa 0%, #f86803 100%);
            z-index: 1;
        }

        .email-header {
            padding: 32px 24px 0;
            text-align: center;
        }

        .logo-rounded {
            border-radius: 12px;
            max-height: 64px;
            margin-bottom: 12px;
            background-color: #06152e;
            padding: 4px;
            border: 1px solid #1b405f;
        }

        .brand-section { margin-bottom: 16px; }
        .brand-name { font-size: 24px; font-weight: 700; color: #ffffff; margin-bottom: 4px; line-height: 1.2; letter-spacing: -0.3px; }
        .tagline { font-size: 14px; color: #94a6ba; font-weight: 400; margin-bottom: 8px; }
        .parent-brand { font-size: 11px; color: #71859b; font-style: italic; font-weight: 400; margin-bottom: 0; }
        .parent-brand a { color: #71859b; }

        .brand-divider {
            border: 0;
            height: 1px;
            background: #1b405f;
            margin: 16px 24px;
        }

        .email-header h1 {
            margin: 20px 24px 0;
            padding: 20px 0 0;
            font-size: 20px;
            font-weight: 500;
            color: #ffffff;
        }

        .email-body {
            padding: 36px 28px;
            font-size: 16px;
            line-height: 1.75;
            color: #c5d2e0;
        }

        .email-body p { margin-bottom: 1.5em; }
        .email-body strong { color: #ffffff; }

        .cta-button {
            display: inline-block;
            margin: 24px 0;
            padding: 14px 32px;
            background: #008bfa;
            color: #ffffff !important;
            text-decoration: none;
            font-weight: 600;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 139, 250, 0.3);
        }

        .email-tip {
            background: rgba(0, 139, 250, 0.12);
            border-left: 4px solid #008bfa;
            padding: 20px;
            margin: 24px 0;
            border-radius: 8px;
            font-size: 15px;
            color: #38bdf8;
        }

        .email-tip strong { color: #38bdf8; }

        .email-footer {
            background: linear-gradient(135deg, #010517 0%, #0a2038 100%);
            padding: 32px 24px;
            text-align: center;
            font-size: 13px;
            color: #94a6ba;
            line-height: 1.8;
        }

        .footer-message { margin-bottom: 16px; }
        .footer-message strong { color: #ffffff; font-weight: 600; }
        .footer-attribution { font-size: 12px; font-style: italic; color: #71859b; margin-bottom: 12px; }
        .footer-attribution a { color: #71859b; text-decoration: underline; }
        .copyright { font-size: 12px; color: #506477; }

        @media only screen and (max-width: 620px) {
            .email-container { margin: 20px 10px; }
            .email-body { padding: 24px 16px; }
            .email-header h1 { font-size: 18px; }
            .brand-name { font-size: 20px; }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            @if (isset($logoUrl) && $logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $brandName ?? 'Custospark Academy' }}" class="logo-rounded" width="64" height="64">
            @endif
            <div class="brand-section">
                <div class="brand-name">{{ $brandName ?? 'Custospark Academy' }}</div>
                <div class="tagline">{{ $tagline ?? 'Learn. Build. Launch.' }}</div>
                <div class="parent-brand">
                    A product of <a href="https://www.custospark.com">Custospark Company Ltd</a>
                </div>
            </div>
            <hr class="brand-divider">
            <h1>{{ $title }}</h1>
        </div>

        <div class="email-body">
            @if (! empty($mailBody))
                @if (isset($isHtml) && $isHtml)
                    {!! $mailBody !!}
                @else
                    {!! nl2br(e($mailBody)) !!}
                @endif
            @endif

            @if (isset($ctaUrl) && $ctaUrl)
                <div style="text-align:center;">
                    <a href="{{ $ctaUrl }}" class="cta-button">{{ $ctaLabel ?? 'Continue' }}</a>
                </div>
            @endif

            @if (isset($tip) && $tip)
                <div class="email-tip"><strong>Security tip:</strong> {{ $tip }}</div>
            @endif
        </div>

        <div class="email-footer">
            <div class="footer-message"><strong>{{ $brandName ?? 'Custospark Academy' }}</strong></div>
            <div class="footer-attribution">
                A product of <a href="https://www.custospark.com">Custospark Company Ltd</a>
            </div>
            <div class="copyright">&copy; {{ date('Y') }} Custospark. All rights reserved.</div>
        </div>
    </div>
</body>
</html>