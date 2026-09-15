# Custospark Academy — Entities log

## 2026-09-15 — Legacy certificate issuance + branded QR + receipt signature

- `QrCodeService::brandedPngBytes()` / `brandedDataUri()` — verification QR
  carries the Academy logo centred (~22%) on a white cushion with rounded
  outer corners (12%) and rounded logo (18%); `qrh` error correction so the
  centre occlusion stays scannable. `CertificatePdfService` uses it for all
  certificate QRs. Plain `pngBytes()`/`dataUri()` unchanged.
- `PaymentReceiptService::email()` now signs with
  `StandardEmail::OSCAR_SIGNATURE` (Founder & CEO, AI & Technology Corporate
  Strategist) like every other learner-facing mail.
- `CertificateService::issue()` gains backward-compatible
  `?string $mailTo`, `bool $notifyCertified = true`,
  `?string $referenceTag = null`. Default flow unchanged.
- `EnrollmentNotificationService::certified()` gains `?string $to` override
  and `bool $send` flag (private `send()` gains `?string $emailOverride`).
- New `certificate:legacy-issue` artisan command — creates/keeps the learner
  (generated temp password, printed once), records the offline certificate
  payment (`LEG-CERT-YYYYMMDD-XXXX`, manual, `paid_at` = award date),
  records a silent certification-stage enrollment, issues with `LEG`
  reference tag (`CSA-XXXX-LEG-XXXX`), backdates `issued_at`/`certified_at`,
  re-renders the PDF. Sends exactly 2 emails (certificate PDF + receipt PDF,
  no certified notice) to the learner — or all to `--send-to` in test mode.
