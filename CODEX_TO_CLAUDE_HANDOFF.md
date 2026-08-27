# Codex → Claude handoff

## 1) Branding — validate, do not redesign

Codex has closed the Flutter routing leak that kept bypassing the official branding assets.

### What changed on `codex/app-ui`

- Added `02_flutter_app/lib/common/widgets/amial_brand_logo.dart`.
  - `full` is composed from the official split layers already extracted from the original logo:
    - `assets/brand/logo_wordmark.png`
    - `assets/brand/logo_swoosh.png`
    - `assets/brand/logo_latin.png` (**AMIAL PAY**)
    - `assets/brand/logo_tagline.png`
  - `symbol` uses `assets/branding/icon_foreground.png` with transparent background.
- `Images.logo` no longer points at the legacy flat image; single-image callers now receive the transparent symbol.
- `CustomLogoWidget` now delegates to the canonical brand widget.
- Backend `assets/branding/source/wordmark.png` is synced byte-for-byte to the cleaned Flutter transparent wordmark.
- Added `assets/branding/source/icon_symbol_transparent.png` as the official transparent symbol source.
- Added `BrandAssetRoutingGuardTest` to block legacy logo routing and source drift.

### Claude validation required before deploy

1. Run the repository verification gate and Flutter analyzer/tests.
2. Visual smoke-test at minimum:
   - customer login
   - merchant login
   - POS login
   - quick receive
   - customer home/header
   - merchant shell
   - wholesale shell
   - profile/settings
   - startup splash
3. Test on white, dark-blue, and yellow surfaces. There must be no white rectangular strip baked into any rendered logo.
4. Any visible Latin brand text must read **AMIAL PAY**, never AMYAL PAY.
5. Regenerate/validate Android launcher/adaptive/round icons and iOS icons only from the existing official assets; do not redraw the brand.
6. Preserve `BrandAssetWeightGuardTest`: receipt logo changes must remain below the mPDF/base64 safety ceiling.
7. If `01_backend/public/branding/logo.png` or `logo-full.png` still contains legacy Latin lettering, rebuild the flattened public/receipt logo deterministically from the official split transparent layers, then run all receipt/PDF tests. Do not replace it with generated artwork.

---

## 2) Customer Quick Pay — backend path is missing; build it A→Z before exposing a button

### Current factual state

- The pre-login feature currently implemented in Flutter is **Quick Receive**, not Quick Pay.
- Financial customer routes such as `/v1/customer/send-money` are protected by `auth:api` plus terms/idempotency/zone middleware.
- There is no real pre-login Quick Pay route/guard/transaction contract to which Flutter can safely connect.
- Existing device infrastructure should be reused where appropriate:
  - `user_log_histories`
  - `is_trusted`
  - `is_blocked`
  - `CheckDeviceId`
- Existing idempotency infrastructure must be reused, not duplicated.

### Product rule

“Without logging in” means **without opening the full account session/UI**, not without authentication. A Quick Pay payment must be authorized on a previously enrolled trusted device and must not grant access to balance/history/profile/withdrawal or any other wallet capability.

### Required backend lifecycle

#### A. Enrollment — authenticated account only

Create an authenticated enrollment/revoke/status capability under customer routes, for example:

- `POST /v1/customer/quick-pay/enroll`
- `GET /v1/customer/quick-pay/status`
- `POST /v1/customer/quick-pay/revoke`

Enrollment must:

- require the normal authenticated customer session;
- require the current non-blocked trusted `device-id`;
- require step-up verification (wallet PIN/password policy used by this project);
- enforce KYC/account status/terms/risk eligibility;
- create a **separate revocable Quick Pay grant** bound to `user_id + trusted device record`;
- store only a hash of any opaque grant secret;
- return the secret once for storage in platform secure storage;
- define server-controlled expiry, per-transaction limit, daily limit and revocation state;
- write an audit event for enroll/revoke/limit changes.

Do not put bearer secrets directly into `user_log_histories`; that table is the device registry, not a credential store.

#### B. Limited pre-login Quick Pay guard

Create middleware/guard dedicated to Quick Pay, separate from `auth:api`. It may resolve only the enrolled customer/grant and must expose **no normal customer session**.

The guard must verify at least:

- opaque grant secret hash;
- device-id binding;
- device exists, is trusted and not blocked;
- grant not expired/revoked;
- account active and financially eligible;
- rate limits;
- short-lived server challenge/nonce to prevent replay.

No endpoint protected only by knowledge of phone number/payment address is acceptable.

#### C. Merchant QR resolution

Create a Quick Pay resolver for the merchant QR payload. It must return only confirmation-safe merchant information (merchant id/reference, display name, branch/POS if relevant, category, currency, amount when QR is fixed-amount).

Do not use a raw merchant phone number as the security identity of a payment target. Reject expired/forged/disabled merchant QR payloads.

Suggested shape:

- `POST /v1/quick-pay/resolve`

Input: signed/opaque merchant QR payload.
Output: short-lived `target_token` + display data.

#### D. Quote

Create a server quote before confirmation:

- `POST /v1/quick-pay/quote`

Input: `target_token`, amount/currency.
Output: short-lived `quote_id`, amount, fee, total debit, merchant display data, expiry.

The server must validate:

- currency/amount precision and min/max;
- customer Quick Pay limits;
- KYC/account/zone/risk policy;
- merchant status and ability to receive;
- fee engine;
- sufficient available balance without leaking full balance to the pre-login client.

#### E. Challenge + local step-up

Before final confirmation issue/verify a one-time challenge. Flutter will require biometric or Quick Pay PIN locally before releasing the enrolled grant from secure storage. Server challenge must be one-use and short-lived.

Do not treat `local_auth` success alone as server authentication; the server must still validate the bound grant/challenge.

#### F. Confirm — one atomic money path

Create:

- `POST /v1/quick-pay/confirm`

It must use the existing financial/ledger services rather than creating a parallel balance mutation path.

Mandatory properties:

- `amial.idempotency` or the same underlying idempotency service;
- DB transaction / row locking appropriate to the existing ledger implementation;
- debit and merchant credit atomically;
- fee posting atomically;
- no double-spend under concurrent confirm requests;
- same `quote_id`/challenge cannot be consumed twice;
- immutable transaction reference;
- audit trail identifying `channel=quick_pay`, grant/device, merchant target and idempotency key;
- receipt data and notification only after commit;
- deterministic response for idempotent retries.

#### G. Receipt/status

Provide a limited receipt/status lookup scoped to the Quick Pay grant/transaction only. It must not become a general transaction-history endpoint.

### Required failure codes

Return stable machine-readable codes for Flutter, at minimum:

- `QUICK_PAY_NOT_ENROLLED`
- `QUICK_PAY_GRANT_EXPIRED`
- `QUICK_PAY_GRANT_REVOKED`
- `QUICK_PAY_DEVICE_BLOCKED`
- `QUICK_PAY_CHALLENGE_EXPIRED`
- `QUICK_PAY_REPLAYED`
- `QUICK_PAY_TARGET_INVALID`
- `QUICK_PAY_QUOTE_EXPIRED`
- `INSUFFICIENT_FUNDS`
- `TRANSACTION_LIMIT_EXCEEDED`
- `KYC_REQUIRED`
- `ACCOUNT_RESTRICTED`
- `DUPLICATE_TRANSACTION`

Flutter must map these to specific user states; do not return generic “failed” for all cases.

### Backend test matrix — mandatory

Claude must add feature/integration tests covering:

1. unenrolled device denied;
2. wrong device-id denied;
3. untrusted device denied;
4. blocked device denied;
5. revoked/expired grant denied;
6. forged/expired merchant target denied;
7. quote expiry;
8. insufficient funds;
9. per-transaction/daily limit;
10. KYC/account restriction;
11. duplicate idempotency key returns the original deterministic result;
12. replayed challenge rejected;
13. concurrent double confirm credits merchant exactly once and debits customer exactly once;
14. failure before commit changes no balances/ledger rows;
15. audit and receipt reference created only for committed transaction;
16. Quick Pay credentials cannot call normal customer wallet endpoints.

### Gate for Flutter UI

Do **not** expose a “دفع سريع” button from the login screen until the above contract exists and its integration tests pass. Quick Receive remains a separate feature and must never be renamed or routed as Quick Pay.

After backend is merged, Codex will wire the Flutter flow exactly to the implemented contract:

`entry → scan QR → resolve merchant → amount → quote → biometric/PIN → challenge/confirm → success receipt`, with loading/offline/expired/retry states and no access to the rest of the wallet.
