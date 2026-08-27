# Wholesale UI — contract status

The approved Wholesale UI uses server-owned contracts. The app must not invent
or locally persist financial or inventory truth. Sector eligibility, plan depth
and employee permissions are independently enforced by the Wholesale policy.

## 1. Unit conversion graph — implemented

The server owns `wholesale_product_units`. Every factor resolves to the
product's canonical base unit and invoice stock movements save both the chosen
unit and `base_quantity`. Flutter manages the conversion and the invoice flow
requests a server quote for the chosen unit.

For a product whose base is `قطعة`, practical display units include:

- `1 شدة = 12 قطعة`
- `1 كرتون = 144 قطعة`

The model is a flat factor-to-base list rather than an arbitrary graph, so
cycles are impossible by construction and historical invoice quantities remain
unchanged.

## 2. Expiry / batch tracking — implemented

The server owns `wholesale_product_lots`; products have no fake single expiry
field. Receiving converts quantities to base units, and invoice issue allocates
active, non-expired lots FIFO in the same transaction as stock deduction. The
invoice stores lot allocations for audit and reversal.

The lot contract contains product, batch number, quantity, received date,
expiry, warehouse/location and an active/quarantined/expired/disposed state.
Expiry counts are calculated server-side from real lot quantities.

## 3. Low-stock notification preferences

The backend supports `low_stock_threshold` per product and a package-gated
`low_stock_only=1` query. Flutter reads those real values. Push, SMS or email
preferences must not be shown as operational until the server has a provider,
delivery/audit state, merchant/branch scope, quiet hours and deduplication.

## 4. Wholesale return workflow — implemented

Wholesale has its own request → review → approve/reject workflow. Approval
updates inventory and the customer balance while paid amounts become an explicit
`refund_pending`, never a fabricated cash refund. Return quantities retain the
unit-to-base conversion used by the source invoice.

## Deployment gate

Before release, validate every Wholesale API against a wholesale merchant only,
the three plans (Free, Business, Enterprise), and the exact employee permission.
Historical reads remain available where policy allows; paid writes must fail
closed after downgrade. No visible action may point to a local-only state.
