# Codex → Claude — Wholesale designs, package enforcement, backend closure

## Scope is non-negotiable

The approved screenshots belong to **merchant + `business_type=wholesale` only**.
They are not a generic merchant skin.

Codex added `AMIAL-WHOLESALE-SCOPE-001` on `codex/app-ui`:

- `WholesaleEntitlementGate` enforces merchant+wholesale at public/deep-link entries.
- UI surfaces map to capability codes, never hard-coded plan names.
- `WholesalePlanSurfaceMatrixTest` pins the current plan depth.
- `WHOLESALE_UI_BACKEND_REQUIREMENTS.md` documents server contracts still missing.

Claude must merge/validate these changes and close the server gaps below before calling this sector complete.

## Current intended entitlement depth

Use the entitlement registry as the source of truth; the list below is a validation snapshot, not a second pricing table.

- Wholesale core: invoices, collections, debts, refunds, daily reports/receipts.
- Starter+: products, inventory, barcode, inventory audit, low-stock alerts.
- Business+: customers, suppliers, purchases, advanced reports, Excel export.
- Merchant Pro+: wholesale multi-pricing plus Pro branch/multi-currency/audit depth.
- Enterprise: inherits prior depth and adds enterprise capabilities.

If the registry says otherwise at merge time, fix the tests/UI mapping to the registry intentionally; do not silently create a second truth.

## P0 — server-side entitlement enforcement audit

UI gating is not security. Audit every Wholesale route and controller action against the entitlement registry.

At minimum:

1. **Products**
   - GET list must not leak paid product data after downgrade.
   - create/update/stock mutation require the appropriate product/inventory capabilities in addition to employee RBAC and usage limits.
   - `low_stock_only=1` remains protected by `low_stock_alerts`.

2. **Customers**
   - list/create/update and customer statement require `customers`.

3. **Aging**
   - report requires `advanced_reports`.
   - Excel export, if/when present, requires `excel_export` independently.

4. **Multi pricing**
   - price tiers and product price reads/writes require `wholesale_multi_pricing`; do not protect only the button or only writes.

5. **Dashboard leakage after downgrade**
   - core dashboard may remain available to Wholesale Free.
   - do not return paid-feature detail/counts (for example old product/customer metadata) to an account that no longer owns those capabilities unless the product policy explicitly allows read-only archival access and the entitlement manifest says so.

6. **Two independent axes**
   - plan entitlement and employee permission/RBAC must both pass.
   - Business plan does not let a cashier edit stock merely because the merchant owns inventory.

Return the normal entitlement states/codes so Flutter can distinguish `locked_by_plan`, `locked_by_role`, and `limit_reached`.

## P0 tests

Add integration tests that prove direct HTTP calls cannot bypass Flutter:

- retail/pharmacy/fuel/restaurant/quick_sale merchant cannot call Wholesale APIs;
- Wholesale Free cannot read/write product/customer/advanced-report data that is outside its entitlements;
- Starter can use product/inventory/low-stock but cannot use customers/aging/multi-pricing;
- Business can use customers/suppliers/purchases/advanced reports/Excel but cannot use Pro multi-pricing;
- Pro can use multi-pricing;
- Enterprise inherits all prior Wholesale depth;
- downgrade preserves database rows but enforces the new read/write policy;
- employee without stock permission is denied even on a plan that owns inventory;
- employee without collection/void permission cannot record/void financial actions;
- route and controller tests cover both reads and writes.

## Approved product-create design — do not fake unsupported fields

The screenshot includes:

- name
- barcode
- category
- purchase price
- sale price
- base unit
- unit conversion rows
- initial stock
- low-stock threshold / notification toggle
- expiry alert toggle/date

Current real Wholesale contract supports name/SKU/barcode/manufacturer/unit/base_price/cost_price/low_stock_threshold/initial_stock/description.

Before Flutter renders the richer controls as saved/working:

### Categories
Create merchant-scoped Wholesale categories or explicitly reuse a shared category service with ownership validation.

### Unit conversions
Create a server-owned conversion graph. Required invariants:

- one canonical base unit per product;
- positive conversion factor;
- no cycles;
- no duplicate source→target edge;
- invoice and stock normalization server-side;
- audit original entered unit/quantity plus normalized quantity.

### Expiry
Prefer lots/batches, not one expiry date on the product. Required minimum fields:

- product_id
- lot/batch number
- quantity
- received_at
- expiry_date
- warehouse/location where applicable
- state: active/quarantined/expired/disposed

Disposal must create an inventory movement/audit record; no silent delete.

### Low-stock notification settings
Per-product threshold already exists. The screenshot's persistent settings/channels require a server settings contract. Do not advertise SMS unless a real provider/billing path exists.

Supplier/reorder buttons are Business+ (`suppliers`/`purchases`) even when low-stock viewing is available in Starter.

## Returns design

The current app opens the real generic `MerchantRefundScreen` under `refunds`.
The approved screenshot describes a Wholesale-specific lifecycle:

`requested → under_review → accepted/rejected → stock disposition → refund/credit note`

Build the backend workflow first. It must link invoice + line + quantity + reason + actor + decision + inventory disposition + financial adjustment, and commit inventory/financial effects consistently.

## Aging design

The screenshot belongs to `advanced_reports` (Business+), not to every Wholesale merchant simply because debts exist.

Server buckets must reconcile to the receivable total for the same snapshot/filter. Flutter must not bucket formatted dates itself.

## Invoice/PDF design

Invoices are Wholesale core. Keep list/details/PDF/print/share real.

Validate:

- money display formatting does not mutate stored precision;
- paid/partial/overdue state is server truth;
- PDF/print/share all refer to the same committed invoice;
- receipt/brand weight guards still pass;
- AMIAL official brand assets only.

## Claude completion report required

When done, report separately:

1. routes/actions that received server entitlement enforcement;
2. tests added and exact pass counts;
3. which approved screenshot controls are fully operational;
4. which remain intentionally unavailable because their backend contract is not yet built;
5. any entitlement/pricing discrepancy found between AccessPresets and CapabilityRegistry.
