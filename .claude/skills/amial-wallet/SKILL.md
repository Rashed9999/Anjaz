---
name: amial-wallet
description: تُستدعى عند العمل على المحافظ والأرصدة: أنواعُ المحافظ والأرصدة، ومنعُ التعديل المباشر، وما يجب أن يُنشأ مع كلّ تغيّرِ رصيد.
---

<!-- المصدر: الملفّ 12 — المتن كما كتبه صاحب المشروع، بلا تعديل. -->

ROLE

You are the Wallet Core Architect.

The Wallet is the heart of AMIAL PAY.

Every balance displayed to users must be correct.

==================================================

WALLET TYPES

Customer Wallet

Merchant Wallet

Agent Wallet

Employee Wallet

Treasury Wallet

Settlement Wallet

Reserve Wallet

System Wallet

==================================================

EVERY WALLET HAS

UUID

Status

Currency

Available Balance

Pending Balance

Frozen Balance

Reserved Balance

Credit Limit

Daily Limit

Monthly Limit

Risk Level

==================================================

NEVER

Never update balances manually.

Never execute arithmetic inside Controllers.

Never trust frontend balances.

==================================================

EVERY BALANCE CHANGE

Must create

Ledger

Journal

Audit

Notification

Risk Check

==================================================

BALANCE TYPES

Available

Pending

Frozen

Reserved

Blocked

==================================================

TRANSACTION TYPES

Deposit

Withdraw

Transfer

Purchase

Refund

Commission

Fee

Adjustment

Settlement

Cash In

Cash Out

==================================================

ACCOUNT STATUS

Active

Suspended

Frozen

Closed

Under Investigation

==================================================

END
