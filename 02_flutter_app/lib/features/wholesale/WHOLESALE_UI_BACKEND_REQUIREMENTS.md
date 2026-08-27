# Wholesale UI — contract status

The approved Wholesale UI uses server-owned contracts. This document records
what is implemented and the remaining server requirement; the app must not
invent or locally persist financial/inventory truth.

## 1. Unit conversion graph — implemented

The server now owns `wholesale_product_units`. Every factor resolves to the
product's canonical base unit and invoice stock movements save both the chosen
unit and `base_quantity`. The Flutter product flow manages the conversion and
the invoice flow requests a server quote for the chosen unit.

For a product whose base is `قطعة`, those practical display units become:

- `1 شدة = 12 قطعة`
- `1 كرتون = 144 قطعة`

The supported model is intentionally a flat factor-to-base list rather than an
arbitrary graph; this prevents cycles by construction.

## 2. Expiry / batch tracking for Wholesale products — implemented

The server now owns `wholesale_product_lots`; products have no fake single
expiry field. Receiving converts quantities to base units, and invoice issue
allocates active, non-expired lots FIFO under the same transaction as stock
deduction. The invoice stores its lot allocations for audit and reversal.

The lot contract contains:

- product_id
- lot/batch number
- quantity
- received_at
- expiry_date
- location/warehouse when applicable
- state (active/quarantined/expired/disposed)

Expiry counts must be calculated server-side from real lot quantities.

## 3. Persistent low-stock notification preferences

Existing Wholesale backend already supports `low_stock_threshold` per product and a package-gated `low_stock_only=1` query. The Flutter screen uses those real values.

For actual push/SMS/email notification preferences, add a server-owned settings contract for:

- enabled/disabled
- channels (in-app/push/SMS/email as supported)
- merchant/branch scope
- quiet hours / deduplication window
- last-notified threshold state

Do not make a local Flutter toggle look authoritative before this exists.

## 4. Wholesale return workflow — implemented

Wholesale now has its own request → review → approve/reject workflow. Approval
updates inventory and the customer balance while paid amounts become an
explicit `refund_pending`, not a fabricated cash refund. Return quantities now
also retain the unit-to-base conversion used by the source invoice.
