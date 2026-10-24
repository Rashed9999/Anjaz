# Merchant bridge v0 — integration preparation only

Source repository `Anjaz`, development branch `feat/amial-merchant-bridge-20261024`.

## Why this code exists

- The Flutter application remains owned by Amial Pay. A signed server-to-server bridge will eventually allow its **cashier-only** mode to read catalogue and stock from Amial Merchant.
- The existing merchant owner's web portal remains deployed at its current subdomain. A future cutover will move its implementation to the private `Amial-Merchant` repo.
- Wallet, financial debts, QR payments, settlement and refunds stay in Amial Pay. **This client cannot debit a wallet**.
- Muwathaq remains the source of verified application approval.

## Safe default

`AMIAL_MERCHANT_BRIDGE_ENABLED=false`. No route or controller currently invokes the bridge: deploying this branch alone would add an unused service, **not** route production customer actions elsewhere. Do not switch this flag on until isolated staging has a reachable merchant service, shared secret, callback tests and verified account mappings.

## Proposed staging environment variables (set outside git)

```
AMIAL_MERCHANT_BRIDGE_ENABLED=false
AMIAL_MERCHANT_BASE_URL=https://<private-staging-merchant-host>
AMIAL_MERCHANT_BRIDGE_SECRET=<random-32+-character-staging-secret>
```

Same secret must be set as `AMIAL_INTEGRATION_SECRET` in Merchant **staging**, never in Flutter, web pages or Git. All URLs are configured server side, must be HTTPS, and only paths under `/api/v1/merchant/` are allowed. Caller identity must be derived from trusted Amial session/POS role; do not pass direct user-supplied actor arrays.

## Ported integration contract

See `Amial-Merchant/docs/INTEGRATION_CONTRACT.md` for canonical HMAC and nonce protocol. Amial backend sends signed, short-lived actor claims with branch scope. Replays rejected on Merchant service.

Implemented client operations are only:

- branch-scoped catalog GET
- merchant catalog GET and owner-only POST
- owner stock intake (staging)
- provisioning of an already approved merchant (must be called only by a verified backend event handler)

No POS purchases are proxied yet. Adding a client method does not mean the Muwathaq approval workflow has been wired to it.

## Gate for staging activation

- [ ] Account/branch IDs reconciled with original live data.
- [ ] Existing owner and cashier role derivation is fully audited.
- [ ] Staging-only merchant service running on HTTPS; no production data.
- [ ] Contract signature tests on both ends; event-replay test; unauthorized cross-branch tests.
- [ ] Rollback flag demonstrated without restart and without changing merchant domain.
- [ ] Financial payment orchestration remains in Amial, with no double-processed sale.

**No production cutover is authorized by this document.**
