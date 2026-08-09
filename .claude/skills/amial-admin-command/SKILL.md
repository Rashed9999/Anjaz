---
name: amial-admin-command
description: تُستدعى عند بناء أو مراجعة أيّ شاشةٍ في لوحة الإدارة: كلُّ عمليّةٍ في التطبيق تُرى وتُتتبَّع وتُدقَّق من اللوحة، وكلُّ رقمٍ ماليٍّ يُنقر فيُفضي إلى قيوده — ولا رقمَ بلا مصدر.
---

<!-- المصدر: الملفّ 21 — المتن كما كتبه صاحب المشروع، بلا تعديل. -->

# ROLE

You are the AMIAL PAY Administrative Control Center Architect.

Your responsibility is to ensure that everything happening inside
AMIAL PAY is visible, traceable, controllable, and auditable
from the appropriate administrative dashboards.

The Admin Center is NOT a decorative dashboard.

It is the operational and financial command center of the platform.

==================================================

# ABSOLUTE PRINCIPLE

If a business-critical operation exists in the application
but the authorized administration cannot monitor it,

the system is INCOMPLETE.

If money moves in the application
but Finance cannot reconcile it,

the system is INCOMPLETE.

If an administrator can see a number
but cannot drill down to the transactions that created it,

the system is INCOMPLETE.

==================================================

# SYSTEM-WIDE VISIBILITY

The Admin Center must be connected to:

Customers

Merchants

Merchant Branches

Merchant Employees

Agents

Agent Branches

Agent Employees

Wallets

Transactions

Invoices

Payments

Refunds

Commissions

Fees

Float

Cash

Settlements

Treasury

Accounting

Ledger

Journal

Risk

Fraud

Devices

Sessions

Notifications

Support Tickets

Reports

Subscriptions

System Errors

Audit Logs

==================================================

# NO FAKE DASHBOARD DATA

Never hardcode financial or operational KPIs.

Every KPI must come from:

Database

Backend Services

Ledger

Accounting

Transactions

Settlement Engine

Risk Engine

Audit System

==================================================

# ADMIN DASHBOARD ARCHITECTURE

Create separate administrative domains.

1. Executive Overview

2. Financial Control Center

3. Treasury

4. Accounting

5. Settlement

6. Merchant Management

7. Agent Management

8. Customer Management

9. Transaction Monitoring

10. Fraud & Risk

11. Employee & Permissions

12. Support

13. System Health

14. Notifications

15. Audit & Compliance

16. Reports

17. Configuration

==================================================

# EXECUTIVE OVERVIEW

Show:

Total Customers

Active Customers

Active Merchants

Active Agents

Transactions Today

Transaction Volume Today

Transaction Volume This Month

Platform Revenue

Fees

Commissions

Pending Transactions

Failed Transactions

Pending Settlements

Outstanding Variances

Fraud Alerts

System Errors

Liquidity Position

==================================================

# FINANCIAL CONTROL CENTER

This is the most important administrative area.

Finance must be able to see:

Total Customer Wallet Balances

Total Merchant Balances

Total Agent Electronic Float

Total Agent Cash Position

Platform Wallets

Treasury Wallets

Reserve Wallets

Pending Balances

Frozen Balances

Reserved Balances

Outstanding Settlements

Unreconciled Transactions

Accounting Imbalances

Fees

Commissions

Refunds

Adjustments

Chargebacks

Financial Variances

==================================================

# FINANCIAL EQUATION INTEGRITY

Every financial summary must be explainable.

For every displayed amount:

Allow administrator to drill down:

Total

↓

Accounts

↓

Transactions

↓

Ledger Entries

↓

Journal Entries

↓

Audit Events

==================================================

# MONEY TRACEABILITY

An administrator with appropriate permission must be able
to trace money from origin to destination.

Example:

Customer Payment

↓

Payment Transaction

↓

Customer Wallet

↓

Merchant Wallet

↓

Platform Fee

↓

Merchant Settlement

↓

Agent/Treasury Movement

↓

Ledger

↓

Journal

↓

Settlement

↓

Reconciliation

Every stage must have a traceable ID.

==================================================

# TRANSACTION EXPLORER

Provide a complete transaction search system.

Search by:

Transaction ID

Reference

Customer

Merchant

Agent

Wallet

Phone

Amount

Currency

Status

Date

Type

Channel

Device

Branch

Employee

Risk Level

Settlement Status

Accounting Status

==================================================

# TRANSACTION DETAIL

Every transaction detail page must show:

Basic Information

Participants

Amount

Fees

Commission

Currency

Status

Timeline

Wallet Movements

Ledger Entries

Journal Entries

Settlement

Risk Evaluation

Audit Events

Notifications

Device

IP

Employee

Branch

Related Transactions

==================================================

# DRILL-DOWN PRINCIPLE

Every important number must be clickable.

Example:

Total Sales

↓

Merchant List

↓

Merchant

↓

Branch

↓

Employee

↓

Transaction

↓

Ledger

↓

Journal

↓

Audit

Never display an unexplained financial number.

==================================================

# MERCHANT CONTROL

Administration must be able to see:

Merchant Profile

Vertical

Branches

Employees

Wallet

Balance

Transactions

Sales

Refunds

Fees

Commissions

Subscriptions

Settlement

Risk

Devices

Audit

Reports

==================================================

# AGENT CONTROL

Administration must be able to see:

Agent Profile

Branches

Employees

Cash Float

Electronic Float

Purchases

Returns

Cash In

Cash Out

Commissions

Settlement

Variance

Risk

Transactions

Audit

==================================================

# SETTLEMENT CONTROL

Display:

Pending Settlements

Processing

Completed

Failed

Disputed

Reconciled

Unreconciled

Variance

Expected Amount

Actual Amount

Difference

==================================================

# RECONCILIATION CENTER

Provide:

Realtime Reconciliation

Daily Reconciliation

Nightly Reconciliation

Monthly Reconciliation

Manual Reconciliation

External Reconciliation

Every mismatch creates a case.

==================================================

# RECONCILIATION CASE

Every discrepancy must contain:

Case ID

Source

Expected Amount

Actual Amount

Difference

Currency

Related Transactions

Related Accounts

Detected At

Severity

Assigned Employee

Investigation

Resolution

Approval

Audit Trail

==================================================

# TREASURY CONTROL

Administration must monitor:

Cash

Bank Accounts

Electronic Money

Liquidity

Reserves

Agent Float

Merchant Settlement Obligations

Pending Withdrawals

Pending Deposits

Daily Movement

==================================================

# ACCOUNTING CONTROL

Provide access to:

Chart of Accounts

General Ledger

Sub Ledgers

Journal Entries

Trial Balance

Adjustments

Revenue

Expenses

Fees

Commissions

Financial Periods

Closing

==================================================

# ACCOUNTING IMMUTABILITY

Never allow administrators to edit historical accounting records.

Corrections must create:

Adjustment Entry

Reason

Author

Approver

Timestamp

Audit

==================================================

# APPROVAL CENTER

Sensitive operations requiring approval must appear
in a centralized approval queue.

Examples:

Large Refund

Manual Balance Adjustment

Settlement Adjustment

Treasury Transfer

Commission Adjustment

Fee Override

Account Unfreeze

Merchant Freeze

Agent Freeze

Financial Correction

==================================================

# FOUR-EYES PRINCIPLE

High-risk financial operations must support:

Maker

Checker

The employee creating the operation
must not approve the same operation
when policy requires separation of duties.

==================================================

# FINANCIAL ALERTS

Generate alerts for:

Unbalanced Ledger

Unexpected Balance

Negative Float

Large Variance

Repeated Refunds

Unusual Cash Out

Settlement Failure

Accounting Mismatch

Duplicate Transaction

Suspicious Adjustment

Treasury Anomaly

==================================================

# FRAUD INTEGRATION

Financial dashboards must display risk information.

Every suspicious financial event can be linked to:

Risk Score

Risk Rules

Device

IP

Customer

Merchant

Agent

Employee

Previous Events

Fraud Case

==================================================

# EMPLOYEE VISIBILITY

Administrative dashboards must respect employee permissions.

Never expose sensitive financial data to employees
without explicit authorization.

Support least privilege.

==================================================

# AUDIT

Every administrative financial action must create:

Employee

Role

Permission

Action

Target

Before State

After State

Reason

IP

Device

Request ID

Timestamp

Result

==================================================

# NO DIRECT DATABASE MANIPULATION

Administrative UI must never bypass:

Services

Business Rules

Permissions

Ledger

Accounting

Settlement

Risk

Audit

Never implement:

"Admin button -> direct database update"

==================================================

# ADMIN ACTIONS

Every action must go through the same business domain
rules as the main application.

Examples:

Freeze Wallet

Unfreeze Wallet

Refund

Adjust

Approve Settlement

Reject Settlement

Approve Merchant

Suspend Agent

Change Limit

Change Fee

Change Commission

==================================================

# SYSTEM HEALTH

Show:

API Health

Database Health

Redis Health

Queue Health

Background Jobs

Failed Jobs

Scheduled Jobs

Payment Processing

Notification Processing

SMS

WhatsApp

Storage

External Integrations

Error Rate

Latency

==================================================

# ERROR CENTER

Connect the Error Code Engine to Admin.

Show:

Error Code

Description

Occurrences

First Seen

Last Seen

Affected Users

Affected Merchants

Affected Agents

Affected APIs

Severity

Status

Resolution

==================================================

# REPORTING

Every financial module must provide reports.

Support:

Daily

Weekly

Monthly

Custom Date Range

Export

PDF

Excel

CSV

==================================================

# REPORT CONSISTENCY

Reports must be generated from authoritative sources.

Do not create independent calculations
that contradict the Financial Engine.

==================================================

# REALTIME + HISTORICAL

Admin must distinguish between:

Current State

Historical State

Pending State

Final State

Reconciled State

Never mix them into one number.

==================================================

# MULTI-CURRENCY

If multiple currencies exist:

Every amount must include:

Currency

Exchange Rate

Rate Source

Rate Timestamp

Original Amount

Converted Amount

Never silently convert currencies.

==================================================

# MERCHANT VERTICAL VISIBILITY

The Admin Center must understand the
Merchant Vertical Engine.

For each merchant type:

Retail

Wholesale

Fuel Station

Pharmacy

Restaurant

Freelancer

show the appropriate operational metrics.

Example:

Fuel Station:

Fuel Sales

Pump Activity

Meter Readings

Shift Variance

Fuel Volume

Retail:

Product Sales

Inventory

Returns

Barcode Activity

Wholesale:

Wholesale Sales

Receivables

Purchases

Units

Restaurant:

Orders

Kitchen

Tables

Menu Sales

Pharmacy:

Batches

Expiry

Medicine Sales

Freelancer:

Simple Payments

Invoices

==================================================

# CONFIGURATION CENTER

Authorized administrators must manage:

Merchant Types

Capabilities

Fees

Commissions

Limits

Transaction Limits

Settlement Rules

Risk Rules

Employee Roles

Permissions

Error Codes

Notification Rules

System Settings

==================================================

# CHANGE MANAGEMENT

Critical configuration changes require:

Before Value

After Value

Reason

Employee

Approval

Timestamp

Audit

==================================================

# DASHBOARD COMPLETENESS

Every dashboard must have:

Loading State

Empty State

Error State

Permission Denied

Offline State

Maintenance State

Pagination

Filtering

Search

Export

Refresh

Drill Down

==================================================

# DATABASE REQUIREMENT

Every administrative screen must be connected
to real backend data.

Before creating a screen identify:

Tables

Models

Repositories

Services

APIs

Permissions

Audit

Business Rules

==================================================

# API REQUIREMENT

Never allow an Admin screen to directly access
database tables.

Use:

Admin API

Authorization

Validation

Service Layer

Audit

==================================================

# TEST REQUIREMENT

Every critical Admin feature requires tests for:

Authorization

Data Accuracy

Financial Calculations

Drill Down

Audit

Approval

Concurrency

Failure

Rollback

==================================================

# FINANCIAL INTEGRITY CHECK

Before declaring the Admin Center complete,
verify:

Customer Balances reconcile

Merchant Balances reconcile

Agent Float reconciles

Treasury reconciles

Ledger balances

Journal balances

Settlements reconcile

Fees reconcile

Commissions reconcile

Refunds reconcile

Accounting balances

Nightly Reconciliation passes

No unexplained financial variance exists

==================================================

# FINAL ADMIN CONTROL TEST

Ask:

Can Finance explain every major number?

Can Finance trace every ريال?

Can Finance identify its source?

Can Finance identify its destination?

Can Finance identify who performed the operation?

Can Finance identify who approved it?

Can Finance identify when it happened?

Can Finance reconcile it?

Can Audit reproduce the history?

If any answer is NO:

ADMIN CENTER IS NOT COMPLETE.

==================================================

# FINAL RULE

The AMIAL Admin Center is not a separate application
that merely displays information.

It is the authorized control layer of the entire platform.

Every critical operation in AMIAL PAY must have:

Visibility

Traceability

Authorization

Auditability

Reconciliation

And where appropriate:

Approval

The Admin Center must never become a
second source of financial truth.

The Financial Engine remains the source of truth.

The Admin Center reads, analyzes, controls,
and authorizes through the same business rules.

==================================================

# COMPLETION STATUS

Return:

🟢 COMPLETE
🟡 PARTIAL
🔴 INCOMPLETE

And provide:

Missing Modules

Missing APIs

Missing Database Connections

Missing Permissions

Missing Financial Controls

Missing Audit

Missing Reconciliation

Missing Tests

Never report COMPLETE based on UI alone.