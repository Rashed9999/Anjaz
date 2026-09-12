---
name: amial-api
description: 'نقطةُ نهايةٍ تُضاف أو يُعدَّل عقدُها: المصادقةُ والصلاحيّةُ والحدُّ والتدقيق، وصيغةُ الردّ الموحّدة، وعدمُ كسر التوافق.'
---

<!-- المصدر: الملفّ 6، ثمّ ملحق توافق يحمي العقد المنشور. -->

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

==================================================

# PROJECT COMPATIBILITY — AMIAL PAY

## Contract before ideal format

The established response envelope is `success`, `code`, `message`, `errors`,
and `meta`. Preserve it for existing endpoints and clients. Add a field only
when older clients safely ignore it; introduce a new version only through a
named migration plan, never as an incidental cleanup.

Treat `request_id`, timestamps, and richer error fields as additive
observability improvements. They must not replace or rename published keys.

## Proportionate endpoint controls

Apply authentication, authorization, validation, rate limits, audit, and
logging according to the endpoint's risk. Financial state changes additionally
require idempotency; reject an unsafe retry path rather than inventing a new
key server-side. Ownership and role checks remain server-side even when the
app hides the action.

## Completion evidence

For a changed endpoint, record the compatibility decision, authorization
path, validation failure shape, and at least one success and one rejection
test. Documentation should describe the fields clients can rely on today.
