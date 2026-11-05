<?php

namespace App\Services\BillPay;

/**
 * Optional capability for providers that expose a prepaid/agent balance.
 * The administration surface uses it for an operational snapshot only; it is
 * never used as an authoritative AMIAL wallet balance or as a cached payment
 * authorization decision.
 */
interface BillProviderBalanceInterface
{
    public function balance(): BillProviderBalanceResponse;
}
