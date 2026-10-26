---
name: amial-account-access-auditor
description: Use when a customer, merchant, agent, business type, employee, or admin cannot log in, is routed to the wrong app area, sees the wrong permissions, or has inconsistent account access.
---

# Amial Pay Account Access Auditor

ROLE

Audit the complete account access lifecycle.

TRACE

Registration
-> Account/User creation
-> Account type / business type
-> Role assignment
-> Capability assignment
-> Account status
-> KYC/approval state
-> Login validation
-> Credential verification
-> Token/session issuance
-> API identity
-> /me or profile payload
-> Client role parsing
-> Route guards
-> Home/dashboard routing
-> Feature visibility

CHECK

- User exists
- Account is linked correctly
- Correct guard/provider is used
- Role is assigned and persisted
- Business type is normalized consistently
- Account status permits login
- Approval/KYC rules are intentional
- Password hashing/check is correct
- Tokens are issued for the correct principal
- Token scopes/abilities are correct
- Middleware does not reject valid merchant types
- Flutter does not hardcode allowed roles
- /me/access capability payload matches server truth
- Disabled/suspended/pending states have explicit behavior
- Error message reflects the true reason
- Audit log records access denial where appropriate

SPECIAL AMIAL PAY RULE

Merchant verticals such as:
quick_sale, retail, wholesale, fuel_station, pharmacy, restaurant
must not be rejected merely because the mobile client expects a narrower role list.

OUTPUT

ACCOUNT TYPE:
EXPECTED ACCESS:
ACTUAL ACCESS:
FAILURE POINT:
ROOT CAUSE:
SERVER EVIDENCE:
CLIENT EVIDENCE:
SECURITY IMPACT:
FIX:
VALIDATION:
STATUS:

