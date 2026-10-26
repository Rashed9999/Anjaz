---
name: amial-full-audit
description: Use when the user requests a comprehensive audit, full project review, one-pass inspection, production-readiness check, or asks to discover everything broken or missing in Amial Pay.
---

# Amial Pay Full Audit Orchestrator

ROLE

Coordinate a structured full-system audit. Do not perform a shallow file scan.

AUDIT ORDER

1. Architecture and project structure
2. Build/lint/static analysis
3. Authentication and account access
4. RBAC/capabilities
5. API contracts
6. Database integrity
7. Financial core
8. Double-entry/ledger
9. Deposits
10. Withdrawals
11. Transfers
12. Merchant payments
13. Fees/commissions
14. Settlements
15. Admin dashboard
16. Agent dashboard
17. Merchant flows
18. Flutter navigation/state
19. Broken buttons/routes/dialogs
20. Notifications
21. Audit/support traceability
22. Printing/receipts
23. Security review
24. Regression/test coverage
25. Completeness

USE EXISTING AMIAL SKILLS

Invoke the existing relevant skills instead of duplicating their rules:
amial-architect
amial-impact
amial-completeness
amial-laravel
amial-database
amial-api
amial-financial-core
amial-double-entry
amial-wallet
amial-settlement
amial-rbac
amial-fraud
amial-flutter
amial-banking-ui
amial-dashboard
amial-navigation
amial-interactive-ui
amial-verticals
amial-admin-command
amial-financial-truth
amial-printing

AND USE THESE AUDIT SKILLS

amial-bug-hunter
amial-account-access-auditor
amial-financial-auditor
amial-api-contract-auditor
amial-security-auditor
amial-regression-auditor

RULES

- Evidence over assumptions.
- Do not label placeholders as real data.
- Verify data source for dashboard metrics.
- Trace broken UI controls to their actual destination.
- Distinguish missing feature from broken feature.
- Do not redesign architecture solely to satisfy a skill if the existing project intentionally uses another compatible pattern.
- Financial and security findings take priority over visual polish.
- Never claim production readiness while CRITICAL or HIGH issues remain unresolved.

FINAL REPORT

EXECUTIVE SUMMARY

CRITICAL:
HIGH:
MEDIUM:
LOW:

FINANCIAL INTEGRITY:
SECURITY:
ACCOUNT ACCESS:
API COMPATIBILITY:
DATABASE:
FLUTTER:
ADMIN:
AGENT:
MERCHANT:
PRINTING:
TESTING:

TOP 10 FIXES IN PRIORITY ORDER:

UNVERIFIED AREAS:

FINAL STATUS:
PRODUCTION READY / NOT PRODUCTION READY / PARTIALLY VERIFIED

