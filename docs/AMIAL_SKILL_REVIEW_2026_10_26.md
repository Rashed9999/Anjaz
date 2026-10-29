# AMIAL PAY PRODUCTION READINESS AUDIT

Overall Status: 🔴 NOT READY for activating the new production Merchant bridge.

This report covers the current extraction and approval/access delivery changes. It is not a claim that all application files or all financial operations have been manually audited.

## Skills applied

Applied the uploaded Amial full audit, account access, API contract, bug hunter, regression, security and financial audit guidance, alongside production readiness and the repository compatibility rules. The seven Amial audit skills are added under `.claude/skills/`; existing skills are preserved. The supplied generic archives contain duplicate catalogs; unrelated design/API-provider setup skills are not installed or executed.

Code review used independent Standards and Spec reviews. Regression tests reproduced cross-merchant blocking, approval loss and initially active suspended tenants before the fixes. No uploaded executable or installer was run.

## Evidence and boundaries

- Merchant identity/session changes: PR `Rashed9999/Amial-Merchant#15`, merged as `e8dd4a09f6360ec08c31482d532f30fbbc303627`; 45 tests / 353 assertions on each of SQLite and MySQL, Docker build and HTTP smoke passed. Coolify confirmed healthy on 2026-10-26 at 09:38 UTC. Newer commercial extraction work in develop is preserved.
- Core outbox changes: `Rashed9999/Anjaz#15`, not production-activated. Initial dedicated contract: 7 tests / 31 assertions plus Muwathaq policy and reviewer tests passed. Expanded regression results are in this PR's latest contract CI.
- Full core CI on `bf34cab0934273f0c69a2fa7aa082d7a465d9e02`: 4155 passed, 106 failed, 18 risky, 10 skipped. Baseline `22c6e5ba138c93a5e9044b3b77431b47348fe98d` failed too. Comparing named failures found no new failure names and 12 resolved failures after explicit Merchant assignment.
- New suspended provisioning contract: `Rashed9999/Amial-Merchant#17`; regression initially returned 200 instead of expected 403. Current CI is authoritative for the fix.
- Only isolated generated test accounts/zero-balance wallets are used by CI. No real accounts, funds, credentials or identity documents were used for these tests.

## Production layers

| Layer | Status | Evidence | Gap | Action |
|---|---|---|---|---|
| 1. CI / Quality Gate | 🟡 PARTIAL | PHP, Flutter, Docker and branch CI run | Full core suite fails; Merchant branch was unprotected and Coolify reacts directly to source webhooks | Fix baseline failures and enforce successful checks before deployment |
| 2. Deployment | 🟡 PARTIAL | Rolling update and healthy container verified | Official merchant host uses APP_ENV=staging; no backup checkpoint verified for this release | Separate production data/configuration and validate release/rollback chain |
| 3. Health / Readiness | 🟡 PARTIAL | Merchant /ready checks DB; /health exists | Cache, scheduler and integration readiness are not proven by that response | Extend proportionately and exercise dependency failures in staging |
| 4. Observability | ❓ UNVERIFIED | Safe failure codes and delivery attempts are retained | Central backend monitoring/correlation across services not operationally checked | Verify end-to-end release/request/event tracing |
| 5. Cache / Redis | ❓ UNVERIFIED | Replay middleware requires shared cache | Actual shared production cache and recovery not verified | Verify configuration, isolation and failure behavior without exposing credentials |
| 6. Queues / Scheduler | 🟡 PARTIAL | Durable outbox, ordered delivery per merchant, nonoverlap schedule | Scheduler runtime, alerts and all legacy mutation paths not proven | Verify scheduler and delivery age/attempt alarms; migrate remaining writers |
| 7. Rate Limiting | 🟡 PARTIAL | Login and internal approval throttles exist | Full financial/auth matrix and distributed behavior not re-audited | Run targeted risk-based limit tests |
| 8. Security | 🟡 PARTIAL | Owner/tenant/branch checks, nonce replay protection, SSO revocation | Entire application authorization and security-write propagation remain incomplete | Finish cross-service membership/device/branch contracts and security transition coverage |
| 9. Financial Idempotency | 🟡 PARTIAL | No financial mutation is added; receipt and retry boundaries have tests | Complete money-path concurrent replay audit outside this change | Exercise exact-balance, retries, reversals in isolated financial tests |
| 10. Ledger / Accounting | ❓ UNVERIFIED | Wallet remains in Amial Pay; ERP cannot directly debit it | Full journal/ledger invariants not verified by these extraction tests | Trace representative financial operations through journal and audit |
| 11. Reconciliation | ❓ UNVERIFIED | Repository has reconciliation services | Automated monitored production reconciliation not proven | Verify scheduler, discrepancy detection and alert handling |
| 12. Backup / Restore | ❓ UNVERIFIED | No restore exercise performed in this change | Off-host backup/keys/restore evidence unavailable | Perform approved disposable restore drill |
| 13. Disaster Recovery | ❓ UNVERIFIED | No DR drill performed | RPO/RTO and server-loss recovery unproven | Document ownership and measure a recovery exercise |
| 14. Smoke / Rollback | 🟡 PARTIAL | Docker routing smoke, live login redirect and DB readiness verified | Authenticated cross-service production E2E and rollback drill unproven | Use staging demo flow; keep additive schema on code rollback |
| 15. Edge / WAF / DDoS | ❓ UNVERIFIED | Merchant HTTPS works | Edge policy and protection not checked | Verify externally managed controls using read-only evidence |

## Standards review

Original findings: global head-of-line blocking; initial activation of suspended merchants; missed rapid suspension transitions; command success despite failed delivery; duplicated access predicates. These drove per-merchant ordering, optional initial access state, transactional central-admin capture, nonzero failure reporting and a shared access resolver. Existing queued active provisioning is narrowed at delivery when current access no longer permits it.

Remaining limitations: all legacy/bulk security writers are not proven; network partitions can delay revocation of access already granted; delivery latency, leases/introspection and monitoring require an explicit operating policy before production activation. These limitations must not be hidden by passing unit/feature tests.

## Spec review

The implemented scope is reliable commercial tenant initialization and access projection, not complete mobile POS extraction. Muwathaq remains the approval source; Amial Pay retains the operational identity, immutable merchant payment number and wallet; Merchant owns ERP records. One shared current-approval/security-hold rule now governs the changed login and projection paths.

Employee membership, role/branch/device mapping, cashier shifts, sector/plan capability parity, Flutter consumer migration and existing commerce data migration remain incomplete. Account creation must not be re-enabled in Merchant as a shortcut.

## Top P0 blockers

1. Full core quality gate is failing; existing failure does not authorize declaring a clean platform.
2. Deployment checks must be enforced against direct source/webhook bypass, not merely present as workflow files.
3. Do not activate production integration until the cross-service staging lifecycle and POS authority contracts are verified.

## Top P1 items

1. Complete security mutation capture and bounded revocation behavior during network failures.
2. Monitor outbox pending age, attempts, failures and scheduler freshness with actionable alerts.
3. Separate staging and production configuration/data and demonstrate backup restore/rollback.

## Top 10 fixes in priority order

1. Resolve/reclassify each baseline test failure with behavior evidence; never loosen security rules just to turn CI green.
2. Enforce deployment gates on the actually deployed branches.
3. Complete transactional capture for every merchant access writer.
4. Specify revocation latency and fail-closed behavior during partitions.
5. Establish staff membership/branch/device authority and signed assertions.
6. Move Flutter POS commerce reads/writes through the approved backend contract.
7. Verify receipt/payment/reversal end-to-end without real funds.
8. Prove production monitoring, scheduler and delivery alarms.
9. Restore backups and verify code/schema-compatible rollback on a disposable environment.
10. Reconcile and migrate existing commercial records before retiring the old portal.

UNVERIFIED PRODUCTION-ONLY AREAS: centralized monitoring, shared cache recovery, worker/scheduler health, production secrets rotation, backup restore, DR, reconciliation operation and WAF policy.

FINAL VERDICT: PARTIALLY VERIFIED extraction groundwork. NOT PRODUCTION READY for complete Merchant/POS separation.
