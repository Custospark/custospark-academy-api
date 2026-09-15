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
