# Custospark Academy — Architecture Decision Records

## 2026-09-15 — ADR: legacy graduate onboarding (Obace Peterson)

- Context: learner completed Data Science off-platform; owes nothing but the
  UGX 50,000 certificate fee (collected offline); certificate awarded
  2026-02-18; production course titled "Data Science Fundamentals".
- Decision: title-only rename to "Data Science" (slug kept, URLs stable);
  dedicated `certificate:legacy-issue` command instead of walking the live
  payment state machine (avoids journey-mail spam and fake gateway rows);
  manual paid payment row keeps the payment-first invariant auditable
  (`isPaid('certificate')` true, journal APPROVED entry).
- Mail surface for legacy: exactly 2 emails (certificate PDF + receipt PDF),
  certified notice suppressed — Registry instruction, normal learner flow
  unchanged.
- Reference scheme: payment `LEG-CERT-…`, certificate `CSA-XXXX-LEG-XXXX`.
- Test order agreed with Oscar: local log-mailer → staging (test inbox) →
  production test inbox → production real send on explicit approval.

## 2026-09-15 — ADR: Cohort 3 campaign copy + delivery

- Context: ~150-person Academy contact list (cohort/classmates), Cohort 3
  applications closing in 2 days, tuition fully covered, UGX 25,000
  application fee.
- Decision: reuse the Custosell campaign command shape rather than build a
  subscriptions/contacts table (no schema change, no migration). Campaign copy
  recrafted for conversion — urgent single-sentence hook, free-tuition offer
  first, single CTA, referral moved to a PS. Subject:
  "Cohort 3 closes in 2 days - tuition is on us".
- Delivery discipline: production runs with `--resume --delay=10` so progress
  is monitorable and a failed run resumes without re-emailing anyone. Dry-run
  executed on production before the live send as a safety gate.
- PII note: recipient list carries real names/emails/phones in-repo, matching
  the existing Custosell precedent (private repo).
