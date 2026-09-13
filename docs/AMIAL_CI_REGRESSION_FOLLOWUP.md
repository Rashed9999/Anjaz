# Existing CI blockers — September 13, 2026

The owner authorized fixes and publication to `claude/project-code-review-yjagv`. Email repairs are tracked separately in `AMIAL_EMAIL_OTP_AUDIT.md`. The full pre-email run already had 32 backend failures; these persisted in run `34691256440` and would still block publication after email verification succeeds.

## Impact and diagnosis

- Operations-center summary queries `merchant_profiles.business_name`, a column absent from the schema. Store names belong to `merchants.store_name`; preserve the existing API field and read its real source.
- Staff deactivation disables `pos_users` and the login user but leaves modern role assignments active. Suspend exactly that employee's active assignments in the same transaction. Preserve which assignments were suspended so reactivation cannot revive roles that were already inactive. Explicit assignment clears that suspension marker.
- The shared KYC status service collapses legacy value `3` (not submitted) into pending. Preserve the existing `not_submitted` state already understood by the customer center.
- Donation and positive tier-limit fixtures claim tier 2/3 without approval. Explicitly model approved users in those cases so their accounting/limit assertions reach the protected operation. Keep negative verification cases and production KYC policy intact.
- Concurrent reporting commit `b8572d2f` correctly removed floating-point conversion from `AmialMoney`, but truncated display fractions instead of rounding them. Run `34789867087` caught `1234.567` displaying as `1,234.56` instead of `1,234.57`. Restore decimal rounding with `BigInt`, including carry and negative values, without reintroducing floating-point conversion or changing any server amount.

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
| `01_backend/tests/Feature/CharityPayoutAndFundTraceGuardTest.php` | Give actual donors in payout and anonymous-donation fixtures explicit KYC approval so the tests reach their payout/privacy assertions. |
| `01_backend/tests/Feature/KycSanctionTest.php` | Model approved tier 2/3 users in positive feature/limit cases. |
| `.github/workflows/ci.yml` | Run the previously failing classes before the full backend gate to surface remaining blockers promptly. |
| `02_flutter_app/lib/helper/amial_money.dart` | Round decimal display strings exactly instead of truncating them, preserving the concurrent removal of floating-point conversion. |
| `02_flutter_app/test/amial_money_format_test.dart` | Cover carry, negative zero, configured precision and amounts beyond floating-point integer precision. |
| `docs/AMIAL_CI_REGRESSION_FOLLOWUP.md` | Record existing failures, bounded repairs, per-file reasons and validation evidence. |

## Verification

In [run 34772847400](https://github.com/Rashed9999/Anjaz/actions/runs/34772847400), the email/support/agent gate passed all 59 tests. The existing-blocker gate passed 124 tests (395 assertions) and identified one remaining anonymous-donor fixture lacking KYC approval. That positive fixture is now corrected; the next run must verify it and the full backend suite. Structural checks, Flutter and both Docker builds passed. `git diff --check` and local CI YAML parsing also passed. Full CI gates and manual APK policy remain in place. Additive migrations run through both existing container startup scripts before the service accepts traffic. No real external messages are sent by these tests.

Commit `afb6fff0339cd722a729b192d039634ef8be6434` preserves the concurrent reporting work through `217646ad`. In [run 34789867087](https://github.com/Rashed9999/Anjaz/actions/runs/34789867087), both the expanded email/demo gate and the complete existing-blocker gate passed; the full backend step started. Structural checks, Flutter analysis and both Docker builds also passed. The Flutter test job identified the decimal-rounding regression described above, with the existing rounding assertion unchanged. Full-suite results for the repaired revision remain pending.
