# Bill-provider readiness — September 14, 2026

The owner asked whether Amial Pay supports the failed internet-payment reversal shown in their B Cash receipt and whether external providers can be connected. This assessment reads the actual repository through `0ec645f9`, preserving the 20 subsequent reporting commits after the email/settlement repair; those commits do not add a bill-provider adapter. It does not establish a live provider account or claim a successful real bill payment.

## Conclusion

**PARTIALLY_FIXED / BLOCKED_EXTERNAL:** bill-payment infrastructure exists, but live provider integration is not ready. The only concrete bill-provider implementation is `StubProvider`. A contract, API documentation and test credentials for a chosen provider are required, together with the internal completion work below. The receipt demonstrates money returned with a reference to the failed original payment; it does not establish whether B Cash performed that reversal automatically or manually.

## Existing components

| Component | Actual implementation and limit |
| --- | --- |
| Customer interface | Provider list and payment form are reachable from customer home; repository/API paths are present. |
| Provider catalog | `BillProvider`, `BillService` and `BillServiceProduct` store provider/service/product definitions. Stored URL/key fields do not constitute an implemented transport. |
| Adapter contract | `BillProviderInterface` defines inquiry, payment, status and reversal. Only `StubProvider` implements it; no real HTTP adapter exists. |
| Payment lifecycle | `BillPayService` debits amount plus fee, records an order and handles success, pending or failure. Existing tests use controlled fake provider responses. |
| Failed-payment refund | `refundOrder` returns amount plus fees and records reversal time/reason and an audit event. This is not a complete transaction/receipt reversal trail equivalent to the supplied receipt. |
| Pending reconciliation | A scheduled job checks pending provider confirmations every minute. It selects at most 50 orders between 30 seconds and 24 hours old. Actual production worker/scheduler operation was not verified. |
| Reversal of successful payment | An interface method exists, but no connected real-provider reversal workflow was found. |

## Immediate repair and per-file reasons

The prior resolver silently selected the simulator even for an unknown integration type, and it was called after wallet debit. Its comment claimed the simulator was disabled in production, but no such check existed.

| File | Change and reason |
| --- | --- |
| `01_backend/app/Services/BillPayService.php` | Reject unimplemented integrations, allow the simulator only in local/testing/staging, and resolve the provider before any order creation or debit. A configured URL can no longer turn into a simulated paid service. |
| `01_backend/tests/Feature/BillPayServiceTest.php` | Prove unsupported integrations and production simulation leave the wallet untouched and create no order. Keep existing fake-provider lifecycle tests. |
| `.github/workflows/ci.yml` | Include bill-payment tests in the focused gate before the unchanged full backend suite. |
| `docs/AMIAL_BILL_PROVIDER_READINESS.md` | Record evidence, limits, immediate repair and the work required before live connection. |

The guard is implemented; runtime validation belongs to this commit's CI. It deliberately does not pretend to install a real provider adapter or complete the remaining lifecycle.

## Required before live activation

1. Implement the selected provider's actual authenticated transport and status/error mapping. Confirm supported services, subscriber lookup, fees and provider reference/idempotency semantics using its sandbox.
2. Distinguish confirmed rejection from unknown outcome. Current exception handling immediately refunds even a network timeout; a timeout can occur after the provider accepted a payment. Preserve pending/unknown state and reconcile before deciding the final financial outcome.
3. Serialize finalization/refund under an order lock and enforce idempotency on the state transition. The current refund check uses an unlocked model snapshot, so stale concurrent attempts are not safely excluded.
4. Complete durable recovery for processing orders, missing provider references, accounting failures and orders older than the current 24-hour job window. Add escalation and reconciliation against provider statements.
5. Complete the original-payment/reversal transaction pair, accounting trail, dedicated receipt and customer notification. Current success issues a `fee_charge` receipt; refund credits the wallet and writes audit data without a dedicated linked refund transaction/receipt.
6. Validate service/product ownership, permitted product prices and account eligibility through the financial service. Verify duplicate submissions, repeated callbacks, late success/failure and full rollback with the real adapter before production activation.

No real bill payment or provider refund was initiated during this review. No provider keys or customer destinations were invented. No claim is made that a particular commercial provider supports Yemen.

Primary references for integration behavior: [Reloadly transaction states](https://www.reloadly.com/blog/how-does-the-get-top-up-status-endpoint-work/) distinguishes processing, success and refunded outcomes; [Adyen idempotency guidance](https://docs.adyen.com/development-resources/api-idempotency/) explains safe retry without repeating the financial operation. These are implementation references, not evidence of a live Amial Pay integration.
