# Wholesale UI — A→Z contracts still missing

> AMIAL-WHOLESALE-ACCESS-001
>
> هذه التصاميم تخص **merchant + `business_type=wholesale` فقط**.
> نوع النشاط يحدد القطاع، والباقة تحدد العمق، وصلاحية الموظف تحدد الفعل.
> Flutter لا يقرر الباقة محلياً؛ يقرأ `/merchant/wholesale/access`.

## What is already enforced

The current operational policy is action-level, not screen-level:

- backend middleware protects every `/api/v1/amial/merchant/wholesale/*` path fail-closed;
- Flutter public entries use `WholesalePolicy*` screens;
- package lock and employee permission are independent;
- owner sees upgrade locks; role-locked staff do not get misleading buttons;
- invoice detail shows collection only with `wholesale.collection.record`;
- invoice detail shows void only with `wholesale.invoice.void`;
- read-only product/customer surfaces remain available to authorized actors after downgrade so historical business data is not confiscated by subscription expiry.

### Current package depth for writes/advanced actions

| Action | Required depth |
|---|---|
| Read old products/customers/invoices | archival read + exact Wholesale permission |
| Collect an existing receivable | invoice core + `wholesale.collection.record` |
| Create/update products | Starter `products` |
| Adjust stock / low-stock alerts | Starter `inventory` / `low_stock_alerts` |
| Manage customers/credit | Business `customers` |
| Current invoice builder | Business in practice: `wholesale_invoices + products + customers` |
| Aging/advanced reports | Business `advanced_reports` |
| Excel export | Business `excel_export` |
| Multi-pricing | Merchant Pro `wholesale_multi_pricing` |

The current invoice builder requires a selected customer and product catalog, so a “New invoice” button before those dependencies are entitled would be a dead-end even though `wholesale_invoices` itself is core.

---

## 1. Product categories

The approved product design includes category selection. Wholesale currently has no authoritative category contract.

Build either merchant-scoped Wholesale categories or intentionally reuse a shared catalogue with ownership validation. Required API behavior:

- list/create/update/archive categories;
- category must belong to the same merchant/business;
- archived category cannot be newly assigned but historical products remain readable;
- category changes audited.

Do not ship a local-only dropdown.

## 2. Unit conversion graph

The approved design requires conversions such as:

- `1 كرتون = 12 شدة`
- `1 شدة = 12 قطعة`

Required server invariants:

- one canonical base unit per product;
- factors must be positive decimals;
- no cycles;
- no duplicate source→target edge;
- invoice and stock movements normalize server-side;
- invoice/audit records keep entered unit/quantity and normalized quantity;
- deleting/changing a conversion cannot rewrite historical invoice quantities.

Until this exists, Flutter may save one base `unit` only; conversion rows must not pretend to persist.

## 3. Lots/batches and expiry

Do not model the approved expiry screen with one expiry date on the SKU. Use lots/batches:

- product_id
- lot/batch number
- quantity
- received_at
- expiry_date
- warehouse/location where applicable
- state: active / quarantined / expired / disposed

Server calculates expired / 7-day / 30-day counts from real lot quantities. “إزالة” must create a disposal inventory movement with actor/reason; never silently delete stock.

## 4. Persistent low-stock notification settings

`low_stock_threshold` already exists per product. The approved settings panel additionally needs a server-owned preference contract:

- enabled/disabled;
- in-app/push/SMS/email only when a real provider exists;
- merchant/branch scope;
- default threshold + per-product override;
- quiet hours;
- deduplication window;
- last-notified state;
- delivery failure/audit state.

Do not show SMS as operational without provider + billing/delivery feedback.

Supplier/reorder actions are separately gated by Business `suppliers` / `purchases`.

## 5. Wholesale returns lifecycle

The generic `MerchantRefundScreen` is not the approved Wholesale return workflow and is intentionally not advertised on the new policy dashboard.

Build:

`requested → under_review → accepted/rejected → stock disposition → refund/credit note`

Required links:

- source invoice;
- source invoice line;
- quantity/unit;
- reason/evidence;
- requester/reviewer actors;
- decision reason;
- restock/quarantine/dispose decision;
- financial refund or credit note;
- immutable reference/audit events.

Inventory and financial effects must commit consistently. A failed refund may not leave stock restored, and a failed stock disposition may not leave money refunded.

## 6. Wholesale Excel exports shown in approved designs

The supplied stock-alert and expiry-alert screens show export buttons, but current Wholesale API has no dedicated export endpoints for those reports. Do not add decorative buttons.

Claude must create real exports before Flutter exposes them, for example:

- `GET /merchant/wholesale/reports/stock-alerts/export?format=xlsx`
- `GET /merchant/wholesale/reports/expiry/export?format=xlsx`
- `GET /merchant/wholesale/reports/aging/export?format=xlsx`

Requirements:

- `excel_export` entitlement independently enforced;
- same filters/snapshot as the on-screen report;
- merchant/branch ownership enforced;
- file contains real server data, not client-reconstructed rows;
- stable filename/content type;
- large reports streamed/queued as appropriate;
- audit export actor/time/filter;
- tests ensure a role allowed to view but not export cannot download the file.

## 7. Multi-pricing Flutter screen

Backend product-price/price-tier endpoints already exist and are policy-gated as Merchant Pro. The previous UI only showed a SnackBar telling the merchant to go elsewhere; this violates the no-fake-button rule and is intentionally absent from the operational dashboard.

Build a real screen against the existing endpoints:

- list price tiers;
- list product prices by tier/min quantity;
- create/manage tier only with `wholesale.tier.manage`;
- set price only with `wholesale.price.set`;
- read-only mode with `wholesale.price.view`;
- validation for positive price/min quantity and duplicate tier ranges;
- loading/error/empty/403/402 states;
- refresh after write from server truth.

## 8. Debt-aging correctness

Aging belongs to Business `advanced_reports`.

Server buckets must be calculated from due dates + outstanding balances, and all buckets must reconcile to total receivable for the same snapshot/filter. Flutter must not rebucket formatted dates.

## 9. Invoice documents

List/details/PDF are real invoice data. Validate:

- paid/partial/overdue state is server truth;
- display formatting never mutates stored precision;
- PDF/print/share refer to the same committed invoice/reference;
- official AMIAL branding only;
- existing PDF/brand weight guards still pass.

## Claude validation gate

Before deployment run direct HTTP and Flutter tests for:

- all five Wholesale demo plans;
- owner, sales_manager, sales_rep, collector, accountant, warehouse_staff;
- non-wholesale merchants blocked from Wholesale APIs;
- downgrade preserves historical rows/read rights and blocks new paid writes;
- product quota blocks create but not edit;
- collection/void/create separation by role;
- every Wholesale route discovered from Laravel route collection maps to `WholesaleAccessPolicyService`;
- `flutter analyze` passes;
- no public route opens `WholesalePro*` directly;
- no missing contract above is represented as a working control.
