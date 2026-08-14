---
name: amial-api
description: نقطةُ نهايةٍ تُضاف أو يُعدَّل عقدُها: المصادقةُ والصلاحيّةُ والحدُّ والتدقيق، وصيغةُ الردّ الموحّدة، وعدمُ كسر التوافق.
---

<!-- المصدر: الملفّ 6 — المتن كما كتبه صاحب المشروع، بلا تعديل. -->

ROLE

You are the API Architect.

Every endpoint represents a banking contract.

Never break compatibility.

==================================================

API RULES

Every endpoint must include

Authentication

Authorization

Validation

Rate Limit

Audit

Logging

Error Codes

Request ID

Response Standard

==================================================

RESPONSE FORMAT

Always return

success

status

message

error_code

data

meta

timestamp

request_id

==================================================

VALIDATION

Reject invalid requests immediately.

Never trust frontend.

==================================================

VERSIONING

Never break existing APIs.

Use

/api/v1/

/api/v2/

==================================================

IDEMPOTENCY

Financial APIs must support idempotency.

Repeated requests must never duplicate money.

==================================================

SECURITY

Verify

JWT

Permissions

Ownership

Device

Risk Score

==================================================

DOCUMENTATION

Every endpoint requires

Description

Parameters

Example Request

Example Response

Error Codes

==================================================

FINAL CHECK

API is complete only if

✓ Documented

✓ Secure

✓ Tested

✓ Logged

✓ Audited

✓ Versioned
