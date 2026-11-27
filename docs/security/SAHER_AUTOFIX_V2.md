# Saher AutoFix v2 — Auditable Remediation Workflow

## Status: implementation branch; no merge or deployment

Saher v1 introduced emergency administrative route containment. V2 introduces the
**next, separate obligation**: track remediation of root causes without claiming
that containment, a patch proposal or a test run proves the bug is gone.

## Actual implemented behavior

1. A privileged platform administrator or security operator opens an existing
   OPEN/REOPENED Saher finding.
2. A deterministic RemediationPlanner returns a rule-specific checklist:
   - WRITE_BEHIND_READ_PERMISSION: least-privilege write authorization + negative tests;
   - WRITE_WITHOUT_PERMISSION: action-specific RBAC/permissions + route tests;
   - BUSINESS_GATEWAY_UNSIGNED: preserve signed HMAC boundary + replay/expiry tests;
   - others: investigation required, no inferred code patch.
3. The operator enters a substantive reason and chooses **Create remediation case**.
4. A unique case keyed by finding ID is inserted in saher_remediation_cases.
5. An actor-specific REMEDIATION_CASE_CREATED event is appended to Saher history.
6. The dashboard shows open remediation cases to users with saher.autofix.plan.
   Unprivileged viewers receive only the count, not case details.
7. A later successful Saher rescan may mark a case SCAN_NOT_DETECTED when its
   finding is absent. This state **does not assert a merged PR, deployed build,
   approved fix or proof that the scanner is infallible**. A return of the same
   finding reopens the case's scan assessment.

## Safety properties

- No arbitrary commands, user-supplied shell code, LLM-generated PHP executed on
  the finance/payment host, file writes or GitHub credentials in the app.
- No changes to customer, merchant, wallet, settlement or authentication tables.
- No mutation of findings into RESOLVED by clicking a remediation button.
- Separate RBAC for remediation planning, suppression and emergency quarantine.
- Explicit, documented high-risk actions with reasons and human ownership.
- Idempotent repeated clicks, stable per-finding identity and event history.
- No GitHub app token, Coolify API key, auto-merge or auto-deploy mechanism in V2.

## Third-party patterns to incorporate next

- GitHub Agentic Autofix: auditable PRs and re-runs of code scanning.
- Semgrep Autofix: testable, rule-specific recipes.
- Snyk Agent Fix: remediation tied to the original finding and rescan.
- DefectDojo remediation center: owner, SLA, workflow and finding-to-change links.
- Renovate / Dependabot: dependency vulnerability PRs rather than homegrown
  dependency patching.

## Next step — external least-privilege repair worker

Build a separate service using a **GitHub App installation token** scoped to a
specific repository, contents and PR permissions. It must consume explicit,
verified repair jobs and produce **Draft PRs only**, with diff previews,
required CI and human review. It must not get access to payment databases or
production deployment credentials.

Avoid embedding a general-purpose GitHub write token in the Amial Pay process.
Link the PR and test results back to the case, but only mark a permanent fix
after deployment provenance and a new successful Saher scan.

References:
- https://docs.github.com/en/code-security/concepts/code-scanning/autofix-for-code-scanning
- https://semgrep.dev/products/product-updates/accelerate-remediation-with-semgrep-autofix/
- https://docs.defectdojo.com/triage_findings/remediation_center/pro__remediation_center/
- https://docs.renovatebot.com/security-and-permissions/
