# Wholesale UI — package-aware scope and backend requirements

> AMIAL-WHOLESALE-SCOPE-001
>
> هذه التصاميم تخص **تاجر الجملة (`business_type=wholesale`) فقط**. لا يجوز
> تعميمها على التجزئة أو الصيدلية أو الوقود أو المطعم أو البيع السريع.
>
> قاعدة التنفيذ: **نوع النشاط يحدد ما ينطبق، والباقة تحدد عمق المزايا**.
> Flutter لا يقرر أن شاشة ما "Starter" أو "Business" من عنده؛ يطلب capability
> ويقرأ حالتها من `/me/entitlements`، والخادم هو مصدر الحقيقة.

## Approved visual surfaces

The supplied design set describes these Wholesale surfaces:

1. Wholesale dashboard.
2. Products list + rich product create/edit.
3. Low-stock alerts.
4. Expiry alerts.
5. Wholesale customers + customer credit terms.
6. Wholesale invoices list.
7. Wholesale invoice details / PDF / print / share.
8. Debt-aging report.
9. Returns workflow.

They are design targets, not permission grants. A screen or control is operational only when its server capability and backend contract exist.

## Package/capability matrix

The UI uses the capability code, never a hard-coded plan check. Today the server catalog resolves the following depth:

| Wholesale surface | Capability | Current depth |
|---|---|---|
| Dashboard / invoices / collections / debts | `wholesale_invoices`, `wholesale_collections`, `debts` | Wholesale vertical core |
| Returns entry | `refunds` | Core |
| Products / basic stock / barcode | `products`, `inventory`, `barcode` | Starter+ |
| Stock audit / low-stock alerts | `inventory_audit`, `low_stock_alerts` | Starter+ |
| Customers | `customers` | Business+ |
| Suppliers / purchase orders | `suppliers`, `purchases` | Business+ |
| Aging / advanced reporting | `advanced_reports` | Business+ |
| Excel export | `excel_export` | Business+ |
| Wholesale multi-pricing | `wholesale_multi_pricing` | Merchant Pro+ |
| Branch / multi-currency / audit depth | `branches`, `multi_currency`, `audit_log` | Merchant Pro+ |
| Corporate/API depth | enterprise capabilities | Enterprise |

The exact unlock plan shown to the user must come from the entitlement manifest (`unlock.plan_name` / `unlock.plan_code`) so a pricing change does not require a new app release.

## Current Flutter enforcement

`wholesale_screens.dart` public/deep-link entries are wrapped with `WholesaleEntitlementGate`.

The gate enforces both axes:

- role/business scope: merchant + wholesale only;
- capability state: `available`, `locked_by_plan`, `locked_by_role`, `limit_reached`, or unknown.

Unknown entitlement state is never converted to allowed or denied. The UI asks the user to refresh instead of inventing access.

The Wholesale dashboard already gates internal action navigation by the same manifest. Public wrappers prevent bypass through a known route name/deep link.

## Server enforcement gaps Claude must close before calling the package model complete

UI gating is not security. The following Wholesale routes currently need a server-side entitlement audit so direct API calls cannot bypass what the plans sell:

- product list/create/update/stock operations must enforce the relevant `products` / `inventory` capabilities in addition to usage limits and employee permissions;
- customer list/create/update and customer statements must enforce `customers`;
- aging report must enforce `advanced_reports`;
- Excel/export endpoints must enforce `excel_export`;
- multi-pricing endpoints must enforce `wholesale_multi_pricing` on **read and write**, not only on the entry button;
- low-stock filtered list must remain protected by `low_stock_alerts`;
- dashboard payload must not leak paid-feature metadata after downgrade (for example old product/customer counts) if the caller no longer owns those capabilities.

Employee RBAC and plan entitlement are separate checks: possessing a plan does not grant a cashier permission to edit stock, void invoices, or record collections.

## 1. Unit conversion graph

Current `WholesaleProduct` supports one `unit` string only. The UI now offers practical base units (`قطعة`, `شدة`, `درزن`, `كرتون`, `طبلية`, etc.) through that existing field.

The richer approved product design also shows conversions such as:

- `1 كرتون = 12 شدة`
- `1 شدة = 12 قطعة`

Do **not** persist these only in Flutter. Add a server-owned model/API for product unit conversions with validation against cycles, zero/negative factors, and duplicate units. Invoice quantities and stock movements must resolve to the canonical base unit server-side.

Recommended invariants:

- exactly one canonical/base unit per product;
- conversion graph is acyclic;
- factors are positive decimal values;
- no duplicate source→target edge;
- stock ledger is stored in the canonical unit;
- every invoice line records both entered unit/quantity and normalized quantity for auditability.

Until this exists, the app may choose a base unit but must not show conversion rows as if they are saved.

## 2. Product category and purchase/sale price semantics

The approved create-product design contains category, purchase price, and sale price. `cost_price` and `base_price` already have real server fields; category must not be a local-only dropdown.

If Wholesale categories are to be used, provide a merchant-scoped category contract and validate that a category belongs to the same Wholesale business before assigning it to a product.

## 3. Expiry / batch tracking for Wholesale products

Current `wholesale_products` does not expose a reliable expiry/batch contract. The expiry-alert screen therefore renders an explicit unavailable state when the server does not supply `expiry_date`/`expires_at`; it never converts absence to zero expired products.

The approved expiry-alert design should be backed by lots/batches rather than one expiry date per SKU:

- product_id
- lot/batch number
- quantity
- received_at
- expiry_date
- location/warehouse when applicable
- state (active/quarantined/expired/disposed)

Expiry counts must be calculated server-side from real lot quantities. Stock disposal must create an inventory movement/audit reason; a red “إزالة” button must never delete quantity silently.

## 4. Persistent low-stock notification preferences

Existing Wholesale backend supports `low_stock_threshold` per product and a package-gated `low_stock_only=1` query. The Flutter screen uses those real values.

The supplied design also shows notification settings/channels. For actual push/SMS/email preferences, add a server-owned settings contract for:

- enabled/disabled;
- channels (in-app/push/SMS/email as actually supported);
- merchant/branch scope;
- default threshold;
- per-product override;
- quiet hours / deduplication window;
- last-notified threshold state.

Do not make a local Flutter toggle look authoritative before this exists. SMS must not be displayed as enabled if there is no SMS provider/billing contract.

Supplier/reorder actions on the stock-alert screen belong to `suppliers` / `purchases` (Business+), even though viewing low-stock alerts can be available earlier.

## 5. Wholesale return workflow

The current UI opens the existing real `MerchantRefundScreen` for the `refunds` capability. The supplied design describes a richer merchandise-return lifecycle:

`requested → under_review → accepted/rejected → stock disposition → refund/credit note`

Build it as a backend workflow before replacing the generic entry. Required audit data includes invoice/line references, quantity, reason, actor, review decision, returned-stock disposition, financial adjustment, and immutable references.

A return must not both restock inventory and refund money unless both effects are committed atomically according to the chosen disposition.

## 6. Debt aging

The visual aging report maps to `advanced_reports` (Business+), not merely to the existence of Wholesale debt invoices.

Server buckets must be calculated from invoice due dates and outstanding balances, not recomputed from formatted Flutter dates. Totals across buckets must reconcile to the total receivable for the same filter/snapshot.

Excel export is a second entitlement (`excel_export`) and must be gated independently from viewing the report.

## 7. Invoice/PDF actions

Wholesale invoice list/details are core Wholesale capabilities. Download/print/share must use real generated documents. The UI must format money for display without changing stored precision or invoice totals.

PDF generation remains subject to the existing brand/document weight guards and must not reintroduce the legacy logo asset.

## Required validation matrix

Before deployment, Claude should test at minimum:

- non-wholesale merchant cannot open any Wholesale surface by route/deep link;
- Free Wholesale sees core invoice/debt/refund surfaces but not paid product/customer/report depth;
- Starter unlocks products/inventory/low-stock without customers/aging;
- Business unlocks customers/suppliers/purchases/advanced reports/Excel without Pro multi-pricing;
- Merchant Pro unlocks multi-pricing/branch depth;
- Enterprise inherits all prior Wholesale depth;
- plan downgrade preserves data but blocks paid reads/writes according to policy without leaking counts/details;
- employee permission denial still works even when the plan owns the feature;
- direct API calls cannot bypass the UI entitlement gate;
- limit reached is distinct from plan locked and from role locked;
- no unavailable feature is represented as a working button that fails only after tapping.
