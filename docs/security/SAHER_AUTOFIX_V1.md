# Saher AutoFix — first security remediation release

## Actual delivered capability

Saher AutoFix v1 enables **one-click emergency containment** (with written confirmation) for
a narrow, verified category of Saher findings. It does **not** patch source code,
change a wallet balance, close a finding, or claim AI has fixed an issue.

- The advisor checks the live Laravel route, middleware, method and current rule.
- Only named administrative write routes matching an OPEN/REOPENED HIGH/CRITICAL
  finding of strong confidence can qualify.
- The privileged operation inserts a unique ACTIVE quarantine with actor and reason.
- All subsequent admin writes to that route return HTTP 403 before invoking a controller,
  even when the actor has the original route permissions.
- A privileged release operation requires a reason and is auditable.
- The original finding remains OPEN until a code change has passed review, been
  deployed, and a subsequent successful Saher scan no longer detects the issue.

## Scope and failure behavior

- Guarded classes: SAHER.GUARD.WRITE_WITHOUT_PERMISSION and
  SAHER.GUARD.WRITE_BEHIND_READ_PERMISSION.
- A route in Saher, or an authentication/recovery route, cannot be quarantined.
- Asset IDs must resolve to actual named write routes. No user-supplied file paths,
  shell commands or permission strings are executed.
- Quarantine is recorded in saher_route_quarantines, one row per route name.
- All transitions go to saher_finding_events; repeated clicks are idempotent.
- This does not modify the existing Saher status or evidence.
- If reading the quarantine table fails, admin writes return 503 rather than silently
  bypassing an existing safeguard. Before migrations are installed the guard is inactive;
  run the DB migration before serving the new application code.
- The control requires saher.autofix.quarantine, granted only to platform_admin by
  the migration. The regular Saher read and scanning permissions do not grant this.
- The UI displays expected disruption before activation. Release is possible independently
  of whether the finding remains open, but requires authorization and a reason.

## Related systems and researched implementation patterns (November 2026)

- GitHub Agentic Autofix: context analysis, draft PR, repeated scanning and human review.
  https://docs.github.com/en/code-security/concepts/code-scanning/autofix-for-code-scanning
- Semgrep Autofix: deterministic rule recipes and validated suggestion context.
  https://semgrep.dev/products/product-updates/accelerate-remediation-with-semgrep-autofix/
- Snyk Agent Fix: code proposals and source rescanning.
  https://github.com/snyk/user-docs/blob/main/scan-fix-and-prevent/scan-with-snyk/snyk-code/manage-code-vulnerabilities/fix-code-vulnerabilities-automatically.md
- DefectDojo: findings, assigned remediation actions and audit history.
  https://docs.defectdojo.com/triage_findings/remediation_center/pro__remediation_center/
- Dependabot and Renovate: security dependency updates delivered as pull requests.
  https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-security-updates
  https://docs.renovatebot.com/
- OpenRewrite: typed deterministic transformation recipes (principally Java stack).
  https://docs.openrewrite.org/

## Follow-on phases (not present in v1)

1. Trusted and unit-tested Laravel/Flutter *code-repair recipes*, specific to each
   Saher rule and backed by an explicit policy mapping, never by open-ended string replacement.
2. GitHub App integration with installation tokens scoped to exact repositories and
   PR creation. Preview diff, request review, run CI, record the linked PR against a finding.
3. Independent monitoring in Rased, post-deploy provenance, and automatic scan evidence.
4. Extensible dependency-fix and configuration-only playbooks.

No auto-merge, auto-deploy, privileged production shell execution, or unreviewed financial
data mutation belongs in these phases.

## Operator checklist

1. Back up the database and apply migrations before deploying this application version.
2. Check role grants, then open an eligible Saher finding and read the impact warning.
3. Enter a substantive reason and acknowledge service interruption before quarantine.
4. Verify that the route returns 403 SAHER_ROUTE_QUARANTINED; unaffected routes stay healthy.
5. Fix the code via a separately reviewed GitHub PR, deploy it, and rerun Saher.
6. Once the permanent fix is verified and approved, release the temporary containment
   with a reason and preserve the complete history.
