---
name: amial-api-contract-auditor
description: Use whenever Flutter, web, admin, merchant, or agent clients disagree with Laravel API behavior, fields are missing, parsing fails, statuses differ, or a backend change may break clients.
---

# Amial Pay API Contract Auditor

ROLE

Protect API compatibility across Amial Pay clients.

FOR EACH ENDPOINT

Compare:

- HTTP method
- Route
- Authentication
- Headers
- Request fields
- Required/optional fields
- Types
- Enum/status values
- Validation errors
- Success envelope
- Error envelope
- Pagination/meta
- Nullability
- Timestamps
- Money formatting
- IDs
- Client model/parser
- Client state handling

RULES

1. Server is source of truth for business and financial logic.
2. Do not silently rename fields without updating all consumers.
3. Do not introduce incompatible enum values without client handling.
4. Distinguish transport failure, validation failure, authorization failure, and business rejection.
5. Never infer success solely from HTTP 200 if the project contract includes a success/code field.
6. Check all known clients before changing shared response shapes.
7. Preserve backwards compatibility where feasible.

OUTPUT

ENDPOINT:
SERVER CONTRACT:
CLIENT EXPECTATION:
MISMATCH:
BREAKAGE:
AFFECTED CLIENTS:
FIX STRATEGY:
COMPATIBILITY RISK:
TESTS:
STATUS:

