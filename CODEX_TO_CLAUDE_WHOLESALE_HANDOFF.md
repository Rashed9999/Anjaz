# Codex → Claude — Wholesale A→Z validation and missing contracts

## Scope

The approved screenshots belong to **merchant + `business_type=wholesale` only**. They are not a generic merchant skin.

Codex now added `AMIAL-WHOLESALE-ACCESS-001`: a single action-level policy shared by backend enforcement and Flutter presentation.

### Files to validate

- `app/Services/Wholesale/WholesaleAccessPolicyService.php`
- `app/Http/Middleware/EnforceWholesaleAccessPolicy.php`
- `app/Http/Controllers/Api/V1/Amial/WholesaleAccessController.php`
- `app/Providers/WholesaleAccessServiceProvider.php`
- `tests/Feature/WholesaleAccessPolicyTest.php`
- `tests/Feature/WholesaleFlutterPolicyGuardTest.php`
- Flutter `wholesale_access_controller.dart`
- Flutter `wholesale_policy_screens.dart`
- public compatibility entries in `wholesale_screens.dart`

## Product rule now enforced

There are **two independent gates**:

1. **Package entitlement** — does the merchant organization own the product depth?
2. **Wholesale employee permission** — may this actor perform this exact action?

A paid package never grants a cashier/accountant/collector an action their role does not own. A role grant never bypasses a package lock.

The backend is fail-closed for `/api/v1/amial/merchant/wholesale/*`: a new route that is not mapped by `WholesaleAccessPolicyService::actionFor()` must return `WHOLESALE_ACTION_UNMAPPED`, not execute silently.

## Downgrade/read policy — intentional

Do **not** change this back to “hide all paid data after downgrade”.

A merchant must not lose access to historical business records because a subscription expired. Therefore:

- existing products: readable with `wholesale.product.view` even after downgrade;
- existing customers/debts: readable with `wholesale.customer.view` even after downgrade;
- invoices/PDF/history: readable with `wholesale.invoice.view`;
- collections on existing receivables remain possible when the actor owns `wholesale.collection.record` — blocking collection would stop the merchant from receiving a debt already owed;
- CREATE/UPDATE/advanced tools are package-gated.

This is an archival/read-right policy, not a free feature grant. New Free merchants simply have no paid catalog data unless they previously owned it.

## Effective package depth for the current implemented flow

Use the registry/entitlement service as source of truth. The following is the expected behavior to test:

### Free

- read existing products/customers/invoices according to role;
- collect existing debt according to role;
- cannot create/update product;
- cannot manage customer credit;
- cannot use stock alerts/advanced reports;
- **cannot create the current Wholesale invoice**, despite `wholesale_invoices` being core, because the implemented invoice builder requires both a product catalog (`products`) and customer (`customers`). Showing the button earlier would be a dead-end UI.

### Starter

Adds:

- create/update products;
- inventory/stock adjustment if role permits;
- low-stock alerts;
- product quota enforcement.

Still cannot create the current invoice because customer management is Business depth.

### Business

Adds:

- manage customers/credit;
- current invoice creation becomes A→Z complete;
- advanced aging/reporting;
- Excel export;
- employee/sales-rep depth where applicable.

### Merchant Pro

Adds:

- wholesale multi-pricing entitlement;
- Pro branch/multi-currency/audit/RBAC depth per registry.

### Enterprise

Inherits all prior Wholesale depth plus Enterprise capabilities.

## Wholesale role separation to verify

Existing server templates are deliberate:

- `sales_manager`: create invoices/manage customers/pricing/read collections/reports; **no collection record, no invoice void**.
- `sales_rep`: create invoice + limited collection; **no void, no customer credit changes**.
- `collector`: read invoice + record collection + aging; **no invoice create/void**.
- `accountant`: read + void + financial/reporting; **no invoice create, no collection record**.
- `warehouse_staff`: product management; **no direct stock adjustment unless explicitly granted**.
- owner: all role permissions, still bounded by package/platform controls.

Run `VerticalRoleTemplatesTest` together with the new Wholesale policy tests.

## HTTP contract/codes

Validate direct HTTP calls, not only service state:

- package lock → 402 `WHOLESALE_PLAN_UPGRADE_REQUIRED`
- package quota → 402 `WHOLESALE_PLAN_LIMIT_REACHED`
- employee role lock → 403 `WHOLESALE_PERMISSION_REQUIRED`
- wrong business type → 403 `WHOLESALE_ONLY`
- unmapped Wholesale endpoint → 503 `WHOLESALE_ACTION_UNMAPPED`

Response `meta.wholesale_access` must include action/state/permission/unlock/usage as applicable.

`GET /api/v1/amial/merchant/wholesale/access` must return the full action snapshot used by Flutter.

## Required direct HTTP test matrix

Add/extend integration tests for:

1. retail/pharmacy/fuel/restaurant/quick_sale cannot call Wholesale APIs;
2. Free cannot POST products/customers/invoices or call advanced reports;
3. Free can read old records and collect old debt only with the exact role permission;
4. Starter can manage product/stock/low-stock, not customer management/current invoice creation;
5. Business can complete current invoice flow and advanced reports;
6. Pro can call all multi-pricing read/write endpoints;
7. product quota blocks new product but does not block editing an existing product;
8. sales manager cannot record collection/void;
9. sales rep cannot void/change customer credit;
10. collector cannot create/void invoice;
11. accountant cannot create invoice/record collection;
12. warehouse staff cannot direct-adjust stock unless permission explicitly added;
13. all current Wholesale routes are mapped; add a regression test that discovers routes rather than relying forever on a handwritten list;
14. middleware executes under Passport bearer auth and POS-device middleware in the real route stack;
15. downgrade preserves rows and read access while blocking new writes.

## Flutter validation

Run `flutter analyze` and relevant tests. Verify with both owner and staff accounts:

- public routes import/open only `WholesalePolicy*` screens;
- owner sees package-locked tiles with upgrade route;
- employee does **not** see tiles/actions locked by role;
- products/customers fall back to read-only UI when actor can view but not manage;
- invoice list only shows “new invoice” when `invoice.create` is available;
- invoice detail only shows collection when `collection.record` is available;
- invoice detail only shows void when `invoice.void` is available;
- collector dashboard must not call `/dashboard` metrics if `dashboard.metrics` is denied — it should still reach allowed invoices/collections/report paths;
- no hard-coded `if plan == business/pro` logic in Flutter; use server snapshot.

## Approved screenshot controls that are STILL NOT A→Z

Do not turn these into cosmetic controls. Build the backend contract first, then wire Flutter.

### 1. Wholesale categories

The screenshot has category selection; current Wholesale product contract does not own a category model/API. Add merchant-scoped Wholesale categories (or intentionally reuse a shared catalog with ownership validation).

### 2. Unit conversion graph

Required for e.g. `1 carton = 12 bundles`, `1 bundle = 12 pieces`.

Server requirements:

- canonical base unit per product;
- positive factors only;
- no cycles;
- no duplicate edge;
- invoice/stock normalize server-side;
- audit entered unit/qty and normalized qty.

### 3. Lots/batches and expiry

Do not store one misleading expiry date on SKU. Create lot/batch tracking with product, lot number, quantity, received_at, expiry_date, location/warehouse and state (active/quarantined/expired/disposed). Disposal must create inventory movement/audit.

### 4. Persistent notification channels

Per-product low-stock threshold exists. The screenshot's in-app/SMS/email settings need a server-owned preference contract, deduplication/quiet-hour policy and a real provider. Never advertise SMS without a real delivery/billing path.

### 5. Wholesale return workflow

The generic `MerchantRefundScreen` is **not** the approved Wholesale return design and has been removed from the policy dashboard.

Build:

`requested → under_review → accepted/rejected → stock disposition → refund/credit note`

Link invoice + line + qty + reason + actor + decision + inventory disposition + financial adjustment atomically/auditably.

### 6. Multi-pricing Flutter screen

Backend product-price/price-tier endpoints exist and are Pro-gated, but the previous dashboard action only displayed a SnackBar telling the user to go elsewhere. That is not an operational feature, so the new policy dashboard does not advertise it as a working button.

Either implement a real Wholesale multi-pricing Flutter screen against the existing endpoints, including list/set/tier management/error states, or keep it out of the operational dashboard. Do not restore the SnackBar placeholder.

## Completion report required from Claude

Return separately:

1. exact commands/tests run and pass counts;
2. direct HTTP matrix results for all 5 Wholesale demo plans;
3. role matrix results for owner/sales_manager/sales_rep/collector/accountant/warehouse_staff;
4. any compile/analyzer fixes made;
5. routes discovered that were not covered by the policy;
6. screenshot controls now truly A→Z;
7. missing contracts still intentionally unavailable;
8. deployment result/commit SHA.

Do not report “complete” from source inspection alone.
