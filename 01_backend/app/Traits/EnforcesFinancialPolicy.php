<?php

namespace App\Traits;

use App\Models\User;
use App\Services\KycTierService;
use App\Services\SanctionScreeningService;
use RuntimeException;

/**
 * AMIAL-SECURITY-AUDIT-001 (v2.1)
 *
 * EnforcesFinancialPolicy — طبقة فحص موحدة قبل أي عملية مالية.
 *
 * **سبب الإنشاء (من المراجعة الأمنية):**
 *   اكتُشف أن بعض الخدمات (MerchantService) تنفّذ عمليات مالية دون فحص
 *   zone/KYC/sanction بشكل متسق. هذا الـ trait يوحّد الفحوصات في مكان واحد
 *   لمنع التفاوت بين الخدمات.
 *
 * **الفحوصات الثلاثة (defense in depth):**
 *   1. Zone: المستخدم في SOUTH (سياسة التشغيل)
 *   2. Sanction: غير محظور في قوائم العقوبات
 *   3. KYC: المستوى يسمح بالمبلغ والميزة
 *
 * **الاستخدام:**
 *   use EnforcesFinancialPolicy;
 *
 *   $this->enforceFinancialPolicy($user, 'safe_payment', $amount);
 *   // يرمي RuntimeException إذا فشل أي فحص
 */
trait EnforcesFinancialPolicy
{
    /**
     * فحص شامل قبل عملية مالية.
     *
     * @throws RuntimeException عند فشل أي فحص
     */
    protected function enforceFinancialPolicy(
        User $user,
        string $feature,
        ?string $amount = null,
    ): void {
        // 1) Zone check — المستخدم في المنطقة التشغيلية المسموحة
        $this->enforceZone($user);

        // 2) Sanction check — غير محظور
        $this->enforceSanction($user);

        // 3) KYC tier check — المستوى يسمح بالميزة والمبلغ
        if ($amount !== null) {
            $this->enforceKycTier($user, $feature, $amount);
        }
    }

    /**
     * فحص المنطقة فقط (للعمليات التي لا تخص KYC مثل عرض).
     */
    protected function enforceZone(User $user): void
    {
        $zone = $user->zone_code ?? 'UNKNOWN';

        if ($zone === 'UNKNOWN' || empty($zone)) {
            throw new RuntimeException(
                'حسابك غير مُعيَّن لمنطقة تشغيلية. أكمل التوثيق لتفعيل العمليات المالية.'
            );
        }

        if ($zone !== 'SOUTH') {
            throw new RuntimeException(
                'العمليات المالية متاحة في الجنوب فقط حالياً. منطقتك: ' . $this->zoneNameAr($zone)
            );
        }
    }

    /**
     * فحص العقوبات.
     */
    protected function enforceSanction(User $user): void
    {
        // إذا سبق وحُظر
        if (($user->sanction_status ?? 'clear') === 'blocked') {
            throw new RuntimeException('تم تقييد حسابك. تواصل مع الدعم.');
        }

        // إذا لم يُفحص بعد، افحص الآن (lazy screening)
        if (!($user->sanction_checked ?? false)) {
            try {
                $result = app(SanctionScreeningService::class)->screenUser($user, 'transaction');
                if ($result['result'] === 'confirmed_match') {
                    throw new RuntimeException('تم تقييد حسابك لأسباب تنظيمية. تواصل مع الدعم.');
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // فشل الفحص لا يجب أن يكسر العملية، لكن نسجّله
                \Log::warning('Sanction screening error during transaction', [
                    'user_id' => $user->id, 'err' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * فحص KYC tier والحدود.
     */
    protected function enforceKycTier(User $user, string $feature, string $amount): void
    {
        app(KycTierService::class)->assertTransactionAllowed($user, $amount, $feature);
    }

    private function zoneNameAr(string $zone): string
    {
        return match ($zone) {
            'NORTH' => 'الشمال',
            'MIDDLE' => 'الوسط',
            'OTHER' => 'أخرى',
            'UNKNOWN' => 'غير محددة',
            default => $zone,
        };
    }
}
