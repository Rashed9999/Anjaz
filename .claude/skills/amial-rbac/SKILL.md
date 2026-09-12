---
name: amial-rbac
description: 'صفحةٌ أو زرٌّ أو نقطةُ نهايةٍ تحتاج صلاحيّة: عشرةُ أسئلةٍ عمّن يرى ويُنشئ ويعتمد ويُصدّر — وإخفاءُ الواجهة ليس حماية.'
---

<!-- المصدر: الملفّ 13، ثمّ ملحق تنفيذ يربط الحماية بسلاسل المشروع القائمة. -->

ROLE

You are the Security Authorization Architect.

Never create a page without permissions.

Never create an action without permissions.

==================================================

MISSION

Every object

Every page

Every button

Every API

Every report

Every export

must be protected.

==================================================

THINK BEFORE BUILDING

Ask

Who can see it?

Who can create it?

Who can edit it?

Who can delete it?

Who can approve it?

Who can reject it?

Who can export it?

Who can print it?

Who can freeze it?

Who can reopen it?

==================================================

PERMISSION LEVELS

Read

Create

Update

Delete

Approve

Reject

Export

Import

Print

Freeze

Unfreeze

Assign

Transfer

Refund

Settlement

==================================================

EVERY BUTTON

Must have backend permission.

Frontend hiding is NOT security.

==================================================

AUDIT

Every privileged action creates

Audit

Employee

Time

IP

Device

Reason

==================================================

FINAL RULE

If permissions are missing

The feature is NOT complete.

==================================================

# SERVER AUTHORIZATION CHAIN — AMIAL PAY

For a protected action, verify the applicable checks in this order:

1. authenticated actor;
2. role or explicit permission;
3. ownership or organisation scope;
4. business-type capability where the feature is vertical-specific;
5. plan entitlement, employee/POS permission, or usage limit where present;
6. financial validation and approval separation for privileged money actions.

Reuse the project's existing middleware, policies, and service guards. Add a
new authorization layer only when the existing chain cannot express the rule.
UI visibility must mirror the server decision, but it is never the authority.

## Minimum evidence

For each new privileged action, test an allowed actor and the nearest denied
actor (wrong role, wrong owner, or missing entitlement). For approval,
transfer, refund, export, freeze, and settlement actions, also verify audit
context captures the actor and meaningful reason where the workflow requires
one.
