---
name: amial-verticals
description: تُستدعى قبل بناء أو تعديل أيّ ميزةٍ خاصّةٍ بصنف تاجر (تجزئة · جملة · محطّة وقود · صيدليّة · مطعم · مستقلّ): نواةٌ واحدة ووحداتٌ قطاعيّة وقدراتٌ تقود الواجهة — ولا نسخةٌ ثانية من نظام التاجر لكلّ صنف.
---

<!-- المصدر: الملفّ 20 — المتن كما كتبه صاحب المشروع، بلا تعديل. -->

ROLE

You are the Merchant Platform Architect.

AMIAL PAY must support multiple merchant business models
without duplicating the merchant system.

==================================================

CORE PRINCIPLE

Never create a separate merchant system for every business type.

Build:

Merchant Core

+

Vertical Modules

+

Capabilities

+

Configuration

==================================================

MERCHANT CORE

Every merchant shares:

Profile

Wallet

Branches

Employees

Roles

Permissions

Customers

Payments

Invoices

QR

Subscriptions

Fees

Commissions

Settlement

Accounting

Audit

Risk

Notifications

Devices

Reports

Settings

==================================================

SUPPORTED VERTICALS

Initial verticals:

RETAIL

WHOLESALE

FUEL_STATION

PHARMACY

RESTAURANT

FREELANCER

==================================================

VERTICAL RULE

Each vertical owns only its
business-specific functionality.

Never duplicate Merchant Core.

==================================================

CAPABILITY SYSTEM

Each vertical declares capabilities.

Examples:

products

inventory

barcode

wholesale_pricing

batch_tracking

expiry_tracking

pumps

fuel_types

meter_readings

menu

recipes

modifiers

tables

kitchen

simple_payment

==================================================

DYNAMIC UI

The merchant application must render
features according to enabled capabilities.

Do NOT show irrelevant features.

Example:

Fuel merchant

shows:

Pumps
Fuel
Meters
Fuel Sales

but does not show:

Recipes
Kitchen
Restaurant Tables

==================================================

RETAIL

Support:

Products

Barcode

Inventory

POS

Customers

Discounts

Returns

Invoices

Stock Reports

==================================================

WHOLESALE

Support:

Products

Units

Unit Conversion

Boxes

Cartons

Wholesale Pricing

Customers

Suppliers

Receivables

Purchases

Sales

Returns

==================================================

FUEL STATION

Support:

Stations

Pumps

Fuel Types

Fuel Prices

Meters

Fuel Sales

Shifts

Tank Monitoring

Cash Collection

Variance

==================================================

PHARMACY

Support:

Medicines

Active Ingredients

Manufacturers

Dosage Information

Batch/Lot

Expiry Dates

Barcode

Inventory

Purchases

Sales

Returns

Expiry Alerts

==================================================

RESTAURANT

Support:

Menu

Products

Modifiers

Sizes

Recipes

Ingredients

Tables

Orders

Kitchen

Takeaway

Delivery

Discounts

Invoices

==================================================

FREELANCER

Keep the interface minimal.

Support:

Enter Amount

Customer QR

Payment

Receipt

Invoice

Transaction History

Do NOT force product management.

Do NOT force inventory.

==================================================

DATA MODEL

Prefer shared core tables with
vertical-specific tables.

Example:

merchants

merchant_verticals

merchant_capabilities

merchant_settings

merchant_branches

merchant_employees

merchant_wallets

merchant_transactions

merchant_invoices

merchant_audit_logs

Then vertical-specific tables:

fuel_stations

fuel_pumps

fuel_sales

wholesale_units

wholesale_prices

pharmacy_batches

pharmacy_expiries

restaurant_tables

restaurant_orders

restaurant_recipes

==================================================

NO GOD TABLE

Do not create one enormous merchants table
containing every possible vertical field.

==================================================

NO GOD SERVICE

Do not create one MerchantService containing
all business vertical logic.

==================================================

ISOLATION

Vertical-specific logic must remain isolated.

A change to Restaurant must not break:

Fuel

Pharmacy

Retail

Wholesale

Freelancer

==================================================

PERMISSIONS

Every vertical capability must have permissions.

Example:

fuel.view

fuel.sell

fuel.edit_price

fuel.close_shift

restaurant.manage_menu

restaurant.manage_orders

pharmacy.manage_batches

wholesale.manage_prices

==================================================

AUDIT

Every sensitive vertical action must generate:

Actor

Merchant

Branch

Employee

Action

Before State

After State

Timestamp

Device

IP

Request ID

==================================================

FINANCIAL INTEGRATION

Every financial vertical operation must connect
to the common financial engine.

Never create separate balance systems.

Never bypass:

Ledger

Accounting

Risk

Settlement

Audit

==================================================

UI RULE

The UI must be driven by:

Merchant Type

Capabilities

Permissions

Subscription

Branch

Employee Role

==================================================

EXTENSIBILITY

Adding a new vertical must NOT require rewriting
the Merchant Core.

New vertical process:

Define Vertical

↓

Define Capabilities

↓

Define Settings

↓

Define Database Models

↓

Define Services

↓

Define APIs

↓

Define Screens

↓

Define Permissions

↓

Define Reports

↓

Define Tests

==================================================

QUALITY CHECK

Before declaring a vertical complete verify:

Database

Models

Services

APIs

Permissions

Screens

Navigation

Buttons

Validation

Audit

Risk

Accounting

Settlement

Notifications

Reports

Tests

Documentation

==================================================

FINAL RULE

AMIAL Merchant Center must be
an extensible platform.

Never hard-code the entire business around
the first six merchant types.

The architecture must allow new verticals
without redesigning the core.