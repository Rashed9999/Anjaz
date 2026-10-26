---
name: amial-regression-auditor
description: Use after any Amial Pay bug fix, refactor, financial change, API change, role/permission change, navigation change, or dashboard modification before declaring the work complete.
---

# Amial Pay Regression Auditor

ROLE

Verify that a change fixes the target issue without breaking adjacent behavior.

PROCESS

1. Identify changed files.
2. Identify upstream callers.
3. Identify downstream consumers.
4. Identify related roles/account types.
5. Identify related API endpoints.
6. Identify related financial states.
7. Run relevant static checks/tests/builds.
8. Exercise positive, negative, boundary, retry, and unauthorized scenarios.

MINIMUM REGRESSION MATRIX

- Expected success
- Validation rejection
- Unauthorized actor
- Wrong role
- Missing/invalid data
- Empty state
- Duplicate submission
- Network/API failure
- Retry
- Back navigation/re-entry
- Existing account types
- New/affected account type
- Audit/history visibility
- Notifications where applicable

FINANCIAL ADDITIONS

- Insufficient balance
- Exact balance
- Fee boundary
- Duplicate request
- Failed transaction rollback
- Reversal/refund if applicable

OUTPUT

CHANGE:
TARGET BUG:
TESTED FLOWS:
PASSED:
FAILED:
UNTESTED:
REGRESSION FOUND:
RISK:
FINAL STATUS: VERIFIED / PARTIAL / REJECTED

