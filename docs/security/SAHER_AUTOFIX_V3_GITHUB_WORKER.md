# Saher AutoFix V3 — Reviewed-recipe Draft PR worker

## Delivered in this branch, not yet deployed

- A deterministic Python source patch engine, restricted to exact-match recipes
  from the versioned manifest at .github/saher/approved-recipes.json.
- A manually triggered GitHub Actions workflow:
  - preview: read-only proposal.
  - draft_pr: patch a disposable checkout, lint, and open a Draft PR only.
- GitHub job-scoped, short-lived workflow token for contents and pull requests;
  no GitHub token is stored in the Amial Pay process or payment database.
- Mandatory CREATE_DRAFT_PR confirmation for any push.
- Exact source match, explicit file allowlist, reject stale/missing/duplicated matches,
  reject traversal and symbolic links.
- Always no-op when the recipe is already applied. Never create duplicate PRs
  for a change that landed previously.
- Unit tests for integrity, no-op, source drift, duplicate replacements,
  malicious finding references, unknown recipes, and symlink safety.

## Boundaries and current limitation

This work does not yet wire the Saher UI button directly to GitHub.
The V2 implementation tracks repair cases within Saher, but a separated
authenticated worker will eventually translate reviewed case IDs into a
trusted job dispatch. Never embed a broad GitHub token or production database
credentials in Saher evidence, Amial Pay application secrets or UI code.

The included recipe represents the historical merchant-note authorization fix
SAHER-SECUR-0033. This recipe has already been applied by PR #59; a correct
runner therefore returns ALREADY_APPLIED on the current production source
branch and does not open a new PR. More security-reviewed recipe definitions
will be added only after a vulnerability has a correct, approved policy mapping.

The GitHub workflow_dispatch menu requires that the workflow file exists on
the GitHub default branch. Repository Actions settings must permit workflows
to create PRs. Security restrictions must not be bypassed to make this run.

## Threat model

- Prompt injection: finding reference is validated metadata only; no finding
  narrative is interpreted as code, shell input, patch, or file path.
- Arbitrary patching: hard-coded file allowlist plus pre-approved manifest.
- Code drift: exact matching of the old snippet; fail closed on duplicates.
- Unauthorized execution: explicit manual workflow and required confirmation.
- Production credentials: no payment database credentials supplied to the job.
- Production operations: creates Draft PR only, never merges/deploys.
- Accidental repeat execution: already-applied recipe yields zero changes.

## Operator sequence

1. Security reviewer validates the original finding and selects a reviewed recipe.
2. Run the GitHub Action in preview mode; inspect proposed diff.
3. Re-run with draft_pr and explicit confirmation only if the patch is still needed.
4. Review Draft PR and full CI. Approve merge and manual Coolify deploy separately.
5. Verify the live deployment SHA, rerun Saher and audit Rased telemetry before
   treating the finding as resolved.

Future integration: a separate GitHub App worker limited to a specific repo,
short-lived installation credentials and verified Saher finding IDs, with
independent approval for high-impact actions.
