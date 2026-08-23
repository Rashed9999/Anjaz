# Wholesale UI — Backend requirements not implemented in Flutter

The approved Wholesale UI is implemented against existing server contracts. The following pieces require server contracts before the Flutter UI can make them operational. The app must not invent or locally persist financial/inventory truth for them.

## 1. Unit conversion graph

Current `WholesaleProduct` supports one `unit` string only. The UI now offers practical base units (`قطعة`, `شدة`, `درزن`, `كرتون`, `طبلية`, etc.) through that existing field.

To support conversions such as:

- `1 كرتون = 12 شدة`
- `1 شدة = 12 قطعة`

add a server-owned model/API for product unit conversions with validation against cycles, zero/negative factors, and duplicate units. Invoice quantities and stock movements must resolve to the canonical base unit server-side.

## 2. Expiry / batch tracking for Wholesale products

Current `wholesale_products` does not expose an expiry field. The expiry-alert screen therefore renders an explicit unavailable state when the server does not supply `expiry_date`/`expires_at`; it never converts absence to zero expired products.

Required server contract should preferably model batches/lots rather than one expiry date per SKU:

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

## 4. Wholesale return workflow

The current UI opens the existing real `MerchantRefundScreen` for the `refunds` capability. If Wholesale requires a distinct merchandise-return lifecycle (requested → reviewed → accepted/rejected → stock disposition → refund/credit note), build it as a backend workflow first and then replace the generic entry.
