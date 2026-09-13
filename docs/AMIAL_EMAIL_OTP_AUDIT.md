# Email OTP integration audit — 2026-09-12

Initial baseline: `a5bb4de4b354ed70064b6e8f9ad1e1b3d9ffde9d` on deployment branch `claude/project-code-review-yjagv`. On September 13 the patch was rebased onto `535a38fc90f919570f87f37a057ec5bf0d9eb97f`, preserving the concurrent debt-receipt, operations-alert and email-center additions.

Applicable skills: amial-impact, amial-laravel, amial-api, amial-database, amial-rbac, amial-flutter, amial-navigation, amial-interactive-ui, amial-completeness, amial-production-readiness, diagnosing-bugs, code-review, tdd/testing.
Not applicable to this change: financial posting, settlement, wallet balance changes, plan pricing, APK publication, and unrelated architecture-audit work.

The project owner has authorized repair, documentation of each file's reason, and a fast-forward push to the deployment branch. This audit prioritizes the newly added email verification journeys.

## Impact before implementation

| Area / files | Observed defect and reason for change | Required evidence |
| --- | --- | --- |
| `EmailOtpService.php` | Incorrect OTP updates are rolled back when the transaction throws; an already verified code can mint another token. Issuance has no shared lock. | Wrong attempts persist, exhaustion blocks the correct code, expiry persists, replay fails, cooldown and supersession hold. |
| `EmailOtpController.php` | Recovery consumes proof before the protected write; refresh tokens survive revocation; email confirmation consists of separate commits; existing unverified addresses cannot be verified in place. | Atomic credential change and proof consumption; current owner binding; full API token revocation; legacy enrollment; signed delivery events. |
| `EmailRegistrationController.php`, `RegisterController.php` | Email registration manufactures an internal phone-verification row and creates the account before consuming proof. | Explicit server-only email authorization; phone route retains its OTP gate; failed registration rolls back proof consumption. |
| `EmailIdentityService.php`, identity observer and affected account creation entrypoints | A global email requirement was added without updating every creation form/validator. Recovery must reject disabled or ambiguous owners. | Account-role creation contracts and profile edits respect canonical uniqueness and verified ownership. |
| Email registration, recovery and profile Flutter screens; locale JSON | Current CI reports an explicit RTL direction and 4,605 Arabic literals against the unchanged 4,536 limit. | Translate the new workflow, locale-derived direction, analyzer and existing tests. |
| Focused feature tests | Existing email tests cover the template and identity guard, but not the OTP lifecycle or complete controller journeys. | Fake provider only; no actual message to another person. Cover failures, expiry, replay, recovery, registration, change and webhook. |

## Baseline verification

GitHub Actions run `34691256440`: structural checks and both Docker builds passed; Flutter analysis passed but two localization tests failed. Backend completed with **385 failed, 17 risky, 9 skipped, 3,485 passed**. Most new failures are missing email fields in old account fixtures and creation payloads. The earlier architecture baseline already had 32 backend failures; this patch does not present those as newly introduced email defects. Existing architecture-audit work in a different worktree is excluded.

No live mailbox delivery has been verified. Provider acceptance, delivery webhook, mailbox receipt and deployment are separate facts. Local PHP/Composer/Flutter runtimes are unavailable; executable runtime evidence must come from the repository's CI. Required gates will not be disabled or their thresholds raised.

## Implementation and final verification

Implemented repairs:

- Rejected attempts and expiry commit before an error is returned; a successfully verified OTP cannot create another token. A durable per-mailbox/purpose row lock serializes issuance even for the first request. Failed sends and exhausted challenges obey cooldown.
- Registration uses server-only verified-email authorization rather than a manufactured phone OTP. Account creation, ownership verification and proof consumption share a transaction. The legacy phone route retains its own verification gate.
- Recovery consumes proof together with the protected write, locks and rechecks the current owner, and revokes access tokens, refresh tokens (including those attached to already revoked access tokens), database web sessions and remembered-login cookies. Failed PIN validation leaves proof retryable. Password entry supports the existing server login contract.
- Email change verifies and writes in one transaction while preserving incorrect attempt counters. The challenge binds to the authenticated user, address and prior email identity. A legacy unverified address can be verified in place. The POS device gate also covers both authenticated email routes.
- Support-triggered mail waits for approval commit; rollback cannot emit an unusable code. Failed deferred delivery stays visible on the OTP challenge. The approval payload's pending status records the state when approval was prepared; the email center reads the live delivery state.
- Provider retries use a stable HTTP idempotency key. Error persistence and the admin display retain diagnostic codes only, including for historical errors that might contain echoed payloads. Signed webhook delivery updates cannot revive a locked/expired/superseded challenge or regress delivered status to sent.
- Recovery request replies use the same conditional message and response shape for eligible and unknown/unverified accounts. This removes the response-code oracle; synchronous delivery can still differ in timing, and registration intentionally reports an occupied email.
- Creation validators and forms require email for real phone accounts, including agent branches. Unrelated updates to invalid legacy email records remain possible while email recovery trust is removed. Direct profile promotion of verification flags is rejected.
- The mobile dialog retains a challenge after a bad code or connection error, supports resend, and guards loading/disposal. Recovery and registration validate success envelopes and token expiry. Local credentials are cleared after recovery or email change. New language keys replace hardcoded recovery/profile text; the original 4,536 ceiling is restored instead of the upstream increase to 4,612.

Local verification before the first push: 23 Python inventory/integrity tests passed; source integrity checked 2,726 files with zero failures; CI YAML parsed; Arabic literals measured **4,520 <= 4,536**; locale keys used by the email screens exist in both Arabic and English; `git diff --check` passed. These checks are not PHP execution or Flutter compilation. Focused PHP lifecycle tests have been added to run before the unchanged full backend gate.

Operational requirements: deploy the additive `otp_issuance_locks` migration before the new service runs. SMTP is not needed for the Resend OTP transport, but the actual provider key, verified sender domain, webhook secret and inbox receipt remain unverified here. No real test email was sent. For a previously absent bootstrap phone account, configure its distinct mailbox explicitly in `AMIAL_BOOTSTRAP_EMAILS`; existing identities are preserved and no fictitious recovery addresses are invented. PHPUnit supplies reserved test addresses only in its test environment.

Provider contracts checked against primary documentation: [Resend idempotency keys](https://resend.com/docs/dashboard/emails/idempotency-keys) and [webhook signature verification](https://resend.com/docs/webhooks/verify-webhooks-requests).

Published first repair commit: `d742d06801288198e360b5f7ac99e774640f76e2`, GitHub Actions [run 34753278985](https://github.com/Rashed9999/Anjaz/actions/runs/34753278985). Structural checks, Flutter analysis/tests and both Docker builds passed. The focused PHP suite stopped before running because its private `call()` helper collided with Laravel's public HTTP test helper. The follow-up renames it to `otpRequest()`; this is a test harness defect, not evidence of a successful lifecycle run. Deployment was skipped because the backend gate failed. The next complete run remains pending.

The follow-up incorporates six concurrent operations-alert commits through `fe602d3e53bec28d65f30fd5c4138db86ff17bf1`. Their transport/template changes are preserved; the newly added HTML email receives the same named CSS-variable exemption as the OTP email, without exempting web screens.

Reviewing the concurrently added email center also exposed a missing action: a verified proof remains valid after the original code expires, but the screen hid its revoke button. The follow-up reads the proof expiry, keeps revocation available while either credential is live, shows consumed/expired states accurately and adds real HTTP tests for restricted readers, historical error redaction, CSV formula prefixes and revocation. Its web-only hero now uses shared brand tokens; the mail template retains its explicitly required inline colors.

## Per-file change reasons

| Repository file | Reason |
| --- | --- |
| `.github/workflows/ci.yml` | Run the focused email lifecycle suite before the existing complete backend gate; preserve all mandatory gates and manual APK policy. |
| `01_backend/.env.example` | Document the optional map needed when provisioning previously absent bootstrap phone accounts. |
| `01_backend/app/Console/Commands/EnsureDemoMerchants.php` | Require explicitly configured mailbox identities for absent bootstrap merchants. |
| `01_backend/app/Console/Commands/EnsureDemoStaff.php` | Use the explicitly configured mailbox for an absent bootstrap agent; preserve the existing admin identity. |
| `01_backend/app/Console/Commands/EnsureDemoUsers.php` | Use explicitly configured mailbox identities only when creating absent bootstrap customers. |
| `01_backend/app/Http/Controllers/Admin/AdminHubController.php` | Require email during operator-created customer, merchant and agent account validation. |
| `01_backend/app/Http/Controllers/Admin/AgentController.php` | Validate the required email before creating a legacy agent account. |
| `01_backend/app/Http/Controllers/Admin/CustomerController.php` | Validate the required email before creating a legacy customer account. |
| `01_backend/app/Http/Controllers/Admin/EmailVerificationCenterController.php` | Cover locked/expired/superseded states; expose proof expiry for revocation, hide historical raw provider errors and prevent formula execution in exported masked email cells. |
| `01_backend/app/Http/Controllers/Agent/AgentPortalController.php` | Require the branch mailbox in the branch-creation API contract. |
| `01_backend/app/Http/Controllers/Api/V1/Auth/EmailOtpController.php` | Make recovery/change atomic, recheck owner and active state, revoke refresh/database/remembered sessions, enroll legacy addresses and honor channel settings. |
| `01_backend/app/Http/Controllers/Api/V1/Auth/EmailRegistrationController.php` | Consume real email proof in the same transaction as account creation; remove fabricated phone proof and preserve retryability. |
| `01_backend/app/Http/Controllers/Api/V1/RegisterController.php` | Accept only server-supplied verified-email authorization while retaining the legacy phone gate; require the shared email field. |
| `01_backend/app/Observers/UserEmailIdentityObserver.php` | Block unauthorized trust-flag promotion and allow unrelated updates of invalid legacy email records while removing recovery trust. |
| `01_backend/app/Services/AgentBranchService.php` | Persist and validate the branch's own unique email instead of creating an account without one. |
| `01_backend/app/Services/ApprovalService.php` | Require the unique active verified owner, honor the recovery channel, and send only after approval commit. |
| `01_backend/app/Services/EmailIdentityService.php` | Reject disabled, ambiguous or invalid recovery owners; protect nested authorization and recheck identity under lock. |
| `01_backend/app/Services/Otp/EmailOtpService.php` | Persist rejection counters/expiry, prevent replay, serialize issuance, defer approval delivery, sanitize errors and guard provider retries/events. |
| `01_backend/app/Support/DemoAccountPolicy.php` | Resolve and validate configured bootstrap mailboxes without inventing addresses or changing existing users. |
| `01_backend/config/amial_otp.php` | Read the explicit bootstrap mailbox configuration. |
| `01_backend/database/migrations/2026_09_12_180000_create_otp_issuance_locks_table.php` | Add a stable DB lock row per hashed mailbox/purpose; no existing OTP table provided a first-request serialization key. |
| `01_backend/phpunit.xml` | Supply reserved test-only bootstrap mailboxes while leaving production addresses unconfigured. |
| `01_backend/resources/views/admin-views/amial/hub/users.blade.php` | Make the operator creation form's email field visibly required. |
| `01_backend/resources/views/admin-views/amial/email-center/index.blade.php` | Keep live proof revocation accessible after code expiry, distinguish completion states and use shared web brand colors. |
| `01_backend/resources/views/agent-views/dashboard.blade.php` | Add and submit the required branch email in the agent's branch modal. |
| `01_backend/routes/api/unified-auth.php` | Apply the mandatory POS device gate to both authenticated email-change routes. |
| `01_backend/tests/Feature/AdminCreatedAccountReviewTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AdminHubTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentAlertTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentCommissionAndReportTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentCounterReceiptTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentDailySettlementTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentFundingHierarchyTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentPortalContractTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentPortalTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentReportsTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentSettingsTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentSettlementEngineTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentShiftStatementTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentStaffPortalTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentStaffProfileTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentSupervisionTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentTellerWorkspaceTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentWhatsappTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/AgentWorkTimeTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/BranchInternalRebalanceTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/EmailIdentityGuardTest.php` | Add regressions for trust-flag promotion, invalid legacy updates and explicit bootstrap mailboxes. |
| `01_backend/tests/Feature/EmailVerificationCenterGuardTest.php` | Verify actual permission enforcement, masked/full views with error redaction, safe CSV cells and revocation of a still-live verification proof. |
| `01_backend/tests/Feature/EmailOtpLifecycleTest.php` | Exercise success, failure, expiry, replay, owner binding, atomic registration/recovery/change, rollback, channel settings and signed webhooks with a fake provider. |
| `01_backend/tests/Feature/KycRegulatoryFieldsTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/MerchantAccountIsUsableGuardTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/PortalHostSeparationTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/ReceiptDownloadIntegrityTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/RegistrationDossierTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/RegistrationRolesTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/RoleSyncFuelRoutingTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/SelfRegisteredMerchantIsUsableTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/SignatureRegistrationTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/SiteAndUnifiedLoginTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/VisualIdentityGuardTest.php` | Explicitly exempt the two named inline OTP/operations email templates from CSS-variable enforcement because email clients cannot use the application stylesheet. |
| `01_backend/tests/Feature/ZoneEnforcementGapsTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `01_backend/tests/Feature/ZoneOnRegistrationTest.php` | Update existing account/branch/registration fixtures with explicit reserved test email addresses so the original assertions exercise their intended behavior under the new identity requirement. No production guard is disabled. |
| `02_flutter_app/assets/language/ar.json` | Add Arabic text for the repaired email and profile journeys. |
| `02_flutter_app/assets/language/en.json` | Add matching English translations and placeholders. |
| `02_flutter_app/lib/features/auth/domain/reposotories/auth_repo.dart` | Expose token deletion as an awaitable operation so navigation follows credential removal. |
| `02_flutter_app/lib/features/auth/screens/amial_registration_wizard_screen.dart` | Validate OTP envelopes/expiry, handle expired proof and widget disposal, and use translated, configured lifetime messages. |
| `02_flutter_app/lib/features/forget_pin/screens/forget_pin_screen.dart` | Enforce valid state transitions, preserve retries, accept the server password contract, clear stale login state and translate the recovery journey. |
| `02_flutter_app/lib/features/setting/controllers/edit_profile_controller.dart` | Always clear the loading state after a failed profile request. |
| `02_flutter_app/lib/features/setting/screens/edit_profile_screen.dart` | Use the persistent email-verification dialog, await credential cleanup and translate profile labels. |
| `02_flutter_app/lib/features/setting/widgets/email_identity_dialog.dart` | Keep password/target/challenge state together; support retry and timed resend without discarding a valid challenge. |
| `02_flutter_app/test/translation_round_holds_test.dart` | Restore the pre-email 4,536 ceiling after fixing translations rather than retaining the upstream increase. |
| `docs/AMIAL_EMAIL_OTP_AUDIT.md` | Record owner authorization, findings, per-file reasons, exact evidence and operational limitations. |
