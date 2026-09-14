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
| `01_backend/app/Models/CustomerCreditMovement.php` | Represent the selected sale separately from the official payment transaction reference. |
| `01_backend/database/migrations/2026_09_14_001000_add_sale_target_to_customer_credit_movements.php` | Add a nullable sale pointer with the existing movement ID width; preserve historical movement records. |
| `01_backend/app/Services/CustomerCreditService.php` | Record the optional sale pointer when creating the payment, checking account ownership under the existing lock. |
| `01_backend/app/Services/CustomerCreditSettleService.php` | Pass the already validated selected sale into the same atomic payment write. |
| `01_backend/app/Services/CreditSourceSettlementService.php` | Replay the recorded sale target when calculating open invoices, while retaining legacy targeted and FIFO behavior. |
| `01_backend/tests/Feature/CustomerCreditSettleTest.php` | Verify persisted transaction/selected-invoice linkage, repeat partial settlement and rejection of another account's sale without moving money. |
| `01_backend/tests/Feature/LedgerCoverageGuardTest.php` | Classify five reviewed read-only reporting services explicitly; remove the stale debt-settlement exemption and require that service to keep posting. |
| `docs/AMIAL_CI_REGRESSION_FOLLOWUP.md` | Record existing failures, bounded repairs, per-file reasons and validation evidence. |

## Verification

In [run 34772847400](https://github.com/Rashed9999/Anjaz/actions/runs/34772847400), the email/support/agent gate passed all 59 tests. The existing-blocker gate passed 124 tests (395 assertions) and identified one remaining anonymous-donor fixture lacking KYC approval. That positive fixture is now corrected; the next run must verify it and the full backend suite. Structural checks, Flutter and both Docker builds passed. `git diff --check` and local CI YAML parsing also passed. Full CI gates and manual APK policy remain in place. Additive migrations run through both existing container startup scripts before the service accepts traffic. No real external messages are sent by these tests.

Commit `afb6fff0339cd722a729b192d039634ef8be6434` preserves the concurrent reporting work through `217646ad`. In [run 34789867087](https://github.com/Rashed9999/Anjaz/actions/runs/34789867087), both the expanded email/demo gate and the complete existing-blocker gate passed; the full backend step started. Structural checks, Flutter analysis and both Docker builds also passed. The Flutter test job identified the decimal-rounding regression described above, with the existing rounding assertion unchanged. Full-suite results for the repaired revision remain pending.

## September 14 — complete-run follow-up

Commit `277c16dc0a59c3cb1c00a6fd796d89d152922739`, [run 34790265411](https://github.com/Rashed9999/Anjaz/actions/runs/34790265411): structural checks, both Docker builds and all **325 Flutter tests** passed, including exact decimal rounding. Email/connected cases passed **73 tests / 337 assertions**; prior blockers passed **125 tests / 399 assertions**. The full backend finished in 766.04 seconds: **3,910 passed, 6 failed, 17 risky, 9 skipped, 52,801 assertions**. Deployment was skipped because of the six failures.

Diagnosis and bounded repairs:

- Consecutive registration reused injected, already-persisted User and EMoney objects. The new email guard exposed the attempted overwrite. Both customer and agent paths now create fresh model instances. Existing PEP coverage additionally proves distinct identities and independent wallets; the identity guard remains enabled.
- Two portal tests omitted the company mailbox. Supply that fixture field, keeping their login/host assertions intact.
- Five newly added reporting services only query/aggregate their sources; inspection found no persistence or financial posting calls. Record each read-only classification in the existing ledger guard. Debt settlement now posts explicitly, so remove its outdated exemption and add it to `MUST_POST`.
- Selected-invoice settlement returned the correct allocation but saved only the transaction reference. Subsequent invoice reads lost the selection and replayed the payment FIFO. Store the sale pointer on the newly created payment movement in the same transaction, retain the official transaction reference, and read the pointer during replay. This changes no posting amounts or wallet arithmetic and does not overwrite old movements. Preserve the prior `credit_sale_payment` reference format and FIFO for unselected/legacy payments.

Operational limit: deploy the additive sale-pointer migration before this code. Historical `debt_payment` rows created without a sale pointer cannot have their original selection inferred safely; if such payments occurred in production, reconcile them against original transaction/audit evidence. No historical balances or journal entries are rewritten automatically. The next run must validate these repairs and the full suite; existing risky/skipped counts are disclosed, not represented as successful tests.
