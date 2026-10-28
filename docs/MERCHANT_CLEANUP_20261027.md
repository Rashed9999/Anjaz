# Retired merchant portal cleanup — 2026-10-27

Removed seven unregistered 6Cash portal files: the old route file, five controllers and its unused middleware. The controllers were byte-for-byte identical to their archival copies already in Amial-Merchant at develop `9ebfa4b349dd8d4df8c2ffba2ae9b5d70691c26a`. Removed the middleware import and alias from bootstrap. Updated the provider explanation and added a route-surface regression test for current owner login/dashboard, wallet/debts and the mobile quote API.

## Dependency and impact evidence

The old route file was not loaded by bootstrap or RouteServiceProvider. Its controller callers were confined to that unregistered file; its middleware was referenced only there and in the removed bootstrap alias. Global class/route-reference searches found no active external runtime callers. Historical inventory/doc entries are snapshots, not runtime dependencies. The removal manifest records every original SHA-256 and the exact Git source revision for restoration.

No tables, accounts, money records, merchant numbers or data migrations are deleted. Current `merchant-web.php`, MerchantWebMiddleware, SSO, Muwathaq approval, cashier APIs and wallet/debt/QR/loyalty engines remain. Their names containing “Merchant” do not make them obsolete. The current live merchant dashboard cannot be deleted until data and sector/financial parity have been verified in the independent service.

## Skills used and selection rationale

amial-impact, amial-architect, amial-laravel, amial-api-contract-auditor, amial-rbac, amial-security-auditor, amial-account-access-auditor, amial-completeness and amial-regression-auditor: dependency/route analysis, preservation of live authorization and payment contracts, reversible code cleanup and regression checks. No financial or Flutter behavior is changed.

## Validation

- Source integrity: 3,080 source files, zero failures after deletion.
- Changed PHP syntax and git diff whitespace checked locally.
- Dedicated GitHub job runs the merchant route/login/bridge tests on PHP 8.4 and isolated MySQL. The full repository workflow remains enabled.
- The dedicated CI initially exposed an existing stale customer-directory assertion (old zero-argument JS signature and old heading). Updated it to check the current API binding, directory heading and search control; the existing live customer API assertion is retained.
- Local full core suite is unavailable on PHP 8.3: the existing lock file requires PHP 8.4.1+ for several packages. The lock is preserved rather than weakened to make local setup pass.

## Not verified

No production deployment, tenant data migration or complete portal retirement. Existing core full-suite failures must be evaluated from CI; this cleanup does not claim to resolve that separate baseline. No changes were made to the open access-outbox work in Anjaz PR #15.
