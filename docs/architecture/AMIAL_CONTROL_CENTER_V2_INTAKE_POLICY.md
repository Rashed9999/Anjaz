# Amial Control Center V2 — Safe Payment PAUSE_NEW

Status: staged on feature/anjaz/control-center-intake-policies-v2, **no merge / deploy**.

## Why this is a distinct mode and not a generic OFF toggle

Safe Payment has two kinds of operations:
- **New intake:** create a new payment.
- **Existing lifecycle:** read a funded payment, upload evidence, verify delivery,
  buyer confirmation/cancel, seller actions, refunds, disputes and ledger settlement.

Pausing new intake while funds are held must be possible without preventing
customers from resolving already funded transactions. V1's generic
full-disable protection would correctly refuse an OFF operation with held funds,
but cannot express the operationally useful PAUSE_NEW state.

## Actual V2 semantics

- Independent policy table control_center_policies stores:
  feature_key, mode RUNNING/PAUSE_NEW, revision, changed_by, reason, timestamps.
- In an already deployed instance, the migration initializes the mode
  from the preexisting feature flag, so a disabled legacy feature is not
  silently restarted during upgrade.
- Pause is a **two-person decision**: maker creates request; checker approves
  after fresh validation and policy revision match.
- Requests created before this migration have null expected_revision and
  cannot be approved as a new-style intake decision. They may be rejected.
- A pause only changes the policy mode. It leaves feature_flags.safe_payment
  unchanged, and never writes safe_payments, ledger or wallet balances.
- The feature middleware prevents new Safe Payment writes even when called
  by admin. It reads the live versioned mode without a cache.
- Safe Payment read requests and the audited existing payment lifecycle
  routes remain available under their existing authorization.
- Resume is another maker-checker change; a legacy disabled feature can only
  be re-enabled by the approving checker, not by a self-approved request.
- New-mode table absence during a staged release falls back to the original
  feature flag behavior until the migration is applied; once the table exists,
  a missing/unknown policy fails closed for new intake.
- The UI labels this precisely as "وقف استقبال الجديد" rather than implying
  total shutdown of Safe Payment.

## Route and data-level invariants

1. New POST safe-pay.create while PAUSE_NEW: 503 FEATURE_INTAKE_PAUSED.
2. Existing payment seller/buyer lifecycle: still allowed after their own
   normal authentication, authorization, zones and idempotency rules.
3. GET existing funded payment: not blocked by intake pause.
4. Held amount positive: pause-new is allowed; full legacy feature disable
   remains forbidden while funds are held.
5. Old maker cannot approve their own request; changing policy revision
   after a request blocks stale approval, even if the policy returns to
   the same visible state.
6. No PR auto-merge, money mutation, production shell or Coolify deploy.

## Acceptance before enabling in production

- Backup & tested restore of the Anjaz database.
- Migration order: PR64 governance first, then V2 policy migration.
- Confirm migrated policy matches real pre-upgrade feature state.
- Approver role grants and two distinct admin operators.
- CI green (PHPUnit, feature security cases, MySQL, Docker, Flutter).
- Browser E2E: preview, submit, pending, approve/reject, and observed state.
- Live API smoke tests for new create and already funded lifecycle, including
  customer read and dispute/settlement permissions.
- Observe account audits, Rased and Saher; immediately stop rollout on
  mismatched desired vs effective state.
- Manual Coolify release only after approval.

## Other controls

This PR does not pretend to enable remote control of Amial Business, Motheq,
Agents, Saher or Rased. Those remain "غير متصل" until a signed capability
contract, effective-state acknowledgment and specific action safety tests exist.
Progressive rollout percentages are not yet supported by server evaluation.
