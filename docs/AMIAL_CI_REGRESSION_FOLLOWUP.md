# Existing CI blockers — September 13, 2026

The owner authorized fixes and publication to `claude/project-code-review-yjagv`. Email repairs are tracked separately in `AMIAL_EMAIL_OTP_AUDIT.md`. The full pre-email run already had 32 backend failures; these persisted in run `34691256440` and would still block publication after email verification succeeds.

## Impact and diagnosis

- Operations-center summary queries `merchant_profiles.business_name`, a column absent from the schema. Store names belong to `merchants.store_name`; preserve the existing API field and read its real source.
- Staff deactivation disables `pos_users` and the login user but leaves modern role assignments active. Suspend exactly that employee's active assignments in the same transaction. Preserve which assignments were suspended so reactivation cannot revive roles that were already inactive. Explicit assignment clears that suspension marker.
- The shared KYC status service collapses legacy value `3` (not submitted) into pending. Preserve the existing `not_submitted` state already understood by the customer center.
- Donation and positive tier-limit fixtures claim tier 2/3 without approval. Explicitly model approved users in those cases so their accounting/limit assertions reach the protected operation. Keep negative verification cases and production KYC policy intact.

Applicable project skills include amial-impact, amial-rbac, amial-database, amial-api, amial-laravel, amial-financial-core, amial-double-entry, amial-financial-truth, amial-wallet, amial-settlement and tdd/testing. No financial posting or balance calculation changes are planned.

## Per-file reasons

| File | Reason |
| --- | --- |
| `01_backend/app/Http/Controllers/Api/V1/Amial/MerchantOperationsCenterController.php` | Read the store name from the actual merchant record while preserving the response contract. |
| `01_backend/tests/Feature/MerchantOperationsCenterTest.php` | Assert that summary reads the correct owner's stored name. |
| `01_backend/app/Http/Controllers/Api/V1/Amial/MerchantStaffController.php` | Lock the employee membership and atomically synchronize login/role activity with session revocation. |
| `01_backend/app/Services/Merchant/MerchantPermissionService.php` | Suspend/resume only the targeted employee's assignments and clear permission cache. |
| `01_backend/app/Models/Merchant/MerchantUserRole.php` | Represent the explicit staff suspension marker. |
| `01_backend/database/migrations/2026_09_13_180000_add_staff_suspension_to_merchant_user_roles.php` | Add a reversible marker distinguishing staff suspension from a separately inactive role assignment. |
| `01_backend/tests/Feature/MerchantStaffTest.php` | Verify disable/re-enable isolation and preservation of previously inactive assignments. |
| `01_backend/app/Services/Kyc/KycAccountStatusService.php` | Preserve the legacy not-submitted state without changing approval or financial eligibility. |
| `01_backend/tests/Feature/DonationsServiceTest.php` | Give positive donation fixtures explicit KYC approval. |
| `01_backend/tests/Feature/CharityServiceTest.php` | Give settlement donor fixtures explicit KYC approval. |
| `01_backend/tests/Feature/CharityPayoutAndFundTraceGuardTest.php` | Give the actual donor in payout fixtures explicit KYC approval. |
| `01_backend/tests/Feature/KycSanctionTest.php` | Model approved tier 2/3 users in positive feature/limit cases. |
| `.github/workflows/ci.yml` | Run the previously failing classes before the full backend gate to surface remaining blockers promptly. |
| `docs/AMIAL_CI_REGRESSION_FOLLOWUP.md` | Record existing failures, bounded repairs, per-file reasons and validation evidence. |

## Verification

Repairs are implemented. `git diff --check` passed and the CI YAML parsed locally; PHP runtime evidence remains pending. The connected email/support/agent gate already passed in [run 34772455446](https://github.com/Rashed9999/Anjaz/actions/runs/34772455446), as did structural checks, Flutter and both Docker builds. Full CI gates and manual APK policy remain in place. Additive migrations run through both existing container startup scripts before the service accepts traffic. No real external messages are sent by these tests.
