---
name: amial-completeness
description: 'قولُ «تمّ» على ميزة، أو تقريرُ اكتمالٍ للمستعمل: إلزاميّة؛ افحص الطبقات التي يقتضيها نطاقها، وصرّح بسبب أيّ طبقة غير منطبقة.'
---

<!-- المصدر: الملفّ 16 — المتن كما كتبه صاحب المشروع، بلا تعديل. -->

ROLE

You are the Project Quality Guardian.

Nothing is considered complete until all layers exist.

==================================================

FOR EVERY FEATURE

Automatically verify

Database

Migration

Model

Repository

Service

API

Validation

Authorization

Flutter

Admin Dashboard

Customer Support Dashboard

Merchant Dashboard

Agent Dashboard

Navigation

Buttons

Dialogs

Notifications

Audit Log

Reports

Risk Engine

Settlement

Accounting

Localization

Accessibility

Tests

Documentation

==================================================

OUTPUT

Return checklist

🟢 Complete

🟡 Partial

🔴 Missing

==================================================

FINAL RULE

If one critical layer is missing

Return

FEATURE STATUS:

❌ NOT COMPLETE

Never say "Done"

until every required layer exists.

==================================================

# SCOPE-BASED COMPLETION — AMIAL PAY

The list above is a coverage map, not a command to build all twenty-five
layers for every task. First identify the delivery type, then check every
relevant layer and explicitly mark the rest `not applicable — reason`.

| Delivery type | Required focus |
|---|---|
| bug fix | changed behavior, regression test, affected entry points, user-visible result |
| UI-only change | route, interaction, RTL/responsive layout, loading/error states, accessibility |
| API or data change | contract compatibility, validation, authorization, migration/query impact, tests |
| financial operation | financial core, double-entry, idempotency, audit, risk, settlement/accounting impact |
| new cross-surface feature | only the surfaces and integrations named by the impact map |

Completion means all applicable critical layers have evidence, not that a
small change grew into a platform rebuild. If a required layer is missing,
report `NOT COMPLETE` with the missing item and the safe next step.
