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

## 2026-09-15 — Cohort 3 campaign email (`email:campaign`)

- New `email:campaign` artisan command — ports the Custosell `email:classmates`
  discipline: CSV recipient list (`name,email,phone`), personalized greeting
  (first-name token), branded `StandardEmail` + `OSCAR_SIGNATURE`, poster
  attachment, single CTA (`academy.custospark.com/register`).
- Resume-safe audit log: every send appended to
  `storage/logs/email-<campaign>.csv` (`email,name,status,error,time`).
  `--resume` skips any email already present (no double-sends); `--dry-run`
  proves the list + personalization without sending; `--batch` and `--delay`
  throttle delivery; `--test="Name|email"` sends one review copy.
- Recipient list at `docs/academy-cohort3-recipients.csv` (148 recipients,
  reused from the Custosell academy cohort list).
- Poster stored at `public/images/custospark_academy_two_day_left_poster.png`
  (renamed from the double-dot source filename).
