<?php

namespace App\Services\BillPay;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C)
 *
 * Contract لكل bill provider integration.
 * أي مزود حقيقي (شركة اتصالات) يطبق هذا الواجهة.
 */
interface BillProviderInterface
{
    /**
     * فحص الحساب (هل الرقم صحيح؟ كم المبلغ المستحق؟).
     */
    public function inquire(string $subscriberAccount, array $extra = []): BillProviderResponse;

    /**
     * تنفيذ الدفع.
     */
    public function pay(
        string $subscriberAccount,
        string $amount,
        string $orderUlid,
        array $extra = [],
    ): BillProviderResponse;

    /**
     * فحص حالة عملية سابقة (للـ pending operations).
     */
    public function checkStatus(string $providerReference): BillProviderResponse;

    /**
     * عكس عملية ناجحة (لو المزود يدعم).
     */
    public function reverse(string $providerReference, string $reason): BillProviderResponse;

    /** اسم المزود (للـ logging) */
    public function name(): string;
}
