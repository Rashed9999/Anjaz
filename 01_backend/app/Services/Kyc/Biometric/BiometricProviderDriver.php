<?php

namespace App\Services\Kyc\Biometric;

use App\Models\User;

/**
 * AMIAL-KYC-BIOMETRIC-RUNTIME-001 — عقدٌ واحد لكل مزود بيومتري حقيقي.
 *
 * لا يقرر هذا العقد اعتماد حساب KYC. مهمته فقط بدء جلسة لدى المزود،
 * والتحقق تشفيرياً من callback، وتحويل نتيجة المزود إلى مفردات أميال
 * المقيدة. الصور/الفيديو والـpayload الخام لا تدخل قاعدة بيانات أميال.
 */
interface BiometricProviderDriver
{
    /** اسم ثابت وآمن يظهر في السجلات واللوحة، مثل alias مسجل في config. */
    public function name(): string;

    /** هل المفاتيح/الإعدادات اللازمة للاتصال والتوقيع موجودة فعلاً؟ */
    public function available(): bool;

    /** بدء محاولة جديدة؛ لا يجوز أن يعيد نتيجة نجاح بيومتري من عنده. */
    public function start(User $user, string $attemptUlid): BiometricStartResult;

    /** يتحقق من توقيع المزود على bytes الطلب الأصلية قبل أي معالجة. */
    public function verifyWebhook(string $rawBody, array $headers): bool;

    /** يحول callback الموقع إلى حدث موحد محدود الحقول. */
    public function parseWebhook(string $rawBody, array $headers): BiometricWebhookEvent;
}
