# Codex → Claude — Merchant demo matrix

## Goal

Provision a deterministic matrix for manual and automated testing of merchant verticals and subscription entitlements:

- 6 business types
- 3 subscription plans (Free · Business · Enterprise)
- 18 merchant accounts

Source of truth: `Database\Seeders\MerchantDemoMatrixSeeder::accounts()`.
Guarded against this document by `tests/Feature/DemoMatrixDocumentTruthGuardTest.php`
— no number is printed here without an account behind it, and no account is
seeded without a line here.

> **Plan merge (AMIAL-PLAN-MATRIX-003).** This file used to publish five
> plans and thirty accounts. The catalogue is three — `Starter` collapsed
> into `Business` and `Merchant Pro` into `Enterprise` — so **twelve of the
> thirty numbers never existed**: every `…001` and every `…003`. Typing one
> of them at the login screen returns "no account", which reads as a broken
> sign-in rather than a retired number. The surviving digits were left
> untouched (`0` · `2` · `4`) because these numbers are memorised: anyone
> who knew a number still lands on the plan they meant.

## Credentials

- Password: `Merchant@2026`
- Transaction PIN: `1234`
- Demo wallet balance: `10,000,000 YER`
- Wallet outflow is deliberately disabled in `merchant_profiles` (`can_transfer_out=false`, `requires_settlement_only=true`). These accounts are for UI/vertical/entitlement testing, not real settlement.

Plan digit:

- `0` = Free (0 ر.س)
- `2` = Business (35 ر.س / month)
- `4` = Enterprise (99 ر.س / month)

Digits `1` and `3` are retired and are **not** seeded.

Accounts (local phone form):

| Vertical | Free | Business | Enterprise |
|---|---:|---:|---:|
| Quick sale | 777211000 | 777211002 | 777211004 |
| Retail | 777212000 | 777212002 | 777212004 |
| Fuel | 777213000 | 777213002 | 777213004 |
| Pharmacy | 777214000 | 777214002 | 777214004 |
| Wholesale | 777215000 | 777215002 | 777215004 |
| Restaurant | 777216000 | 777216002 | 777216004 |

DB phone is always the `967`-prefixed version, e.g. wholesale/business is `967777215002`.

## Mandatory validation before provisioning

1. Run:
   - `php artisan test --filter=MerchantDemoMatrixTest`
   - the existing entitlement/plan matrix tests
   - merchant dispatcher tests
2. Confirm all migrations are current.
3. Confirm none of the 18 reserved phone numbers belong to a real/non-demo account. The Seeder also refuses collisions using marker `AMIAL_DEMO_MERCHANT_MATRIX_V1`.
4. Do not add this Seeder to `DatabaseSeeder` and do not run it from a migration.

## Provisioning

### Non-production demo/staging

Run explicitly:

`php artisan db:seed --class="Database\\Seeders\\MerchantDemoMatrixSeeder" --force`

### Production-like APP_ENV

Do **not** provision on a real financial production environment.

If the deployment is an explicitly approved isolated demo/test deployment but `APP_ENV=production`, set `AMIAL_ALLOW_PRODUCTION_DEMO_MERCHANTS=true` only for the seeding run, execute the Seeder, then remove/unset the override immediately.

## A→Z verification after provisioning

For every account, verify `GET /api/v1/amial/me/access` and `GET /api/v1/amial/me/entitlements` return:

- `role=merchant`
- expected `business_type`
- expected `subscription_plan`
- KYC/verification accepted
- correct capability states for that plan

Then smoke-test one account from every vertical plus all three Wholesale accounts:

- login succeeds with phone + password;
- transaction PIN `1234` is accepted where a non-financial gated test needs it;
- `HomeDispatcherScreen` opens the correct vertical, never another merchant vertical;
- locked capabilities show the correct upgrade state from the server manifest;
- available capabilities open real routes/API;
- Free/Business/Enterprise differences match the plan entitlement tests;
- no known-password demo account can transfer wallet funds outward.

## Wholesale focus

Use these three accounts for the current UI round:

- `777215000` — Free
- `777215002` — Business
- `777215004` — Enterprise

They must show the same Wholesale design language while the feature depth changes strictly according to server entitlements.

## Do not fake missing backend contracts

The existing Wholesale handoff still applies. Unit conversions, lot/expiry tracking, persistent notification preferences and a dedicated Wholesale returns lifecycle require real backend contracts first. Do not enable decorative buttons for them just because the Enterprise demo account exists.
