# Wholesale approved UI round

Implemented screens:

- Professional Wholesale dashboard
- Customers + add/edit form
- Products + add/edit form with real base-unit and low-stock-threshold fields
- Invoice create
- Invoice list + status/search filters
- Invoice detail + real PDF download + real collection action
- Debt aging report
- Returns entry using the existing refund flow
- Package-gated Wholesale low-stock alerts using the real `low_stock_only` endpoint
- Expiry-alert surface that refuses to fabricate expiry data when the server does not provide it

Package behavior uses the existing entitlements manifest. Plan locks, role locks, limits and unknown/unavailable states are not collapsed into one lock state.

No backend/API contract was changed in this round.
