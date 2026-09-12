<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * TestCase — الأساس الذي ترث منه جميع اختبارات Feature/Unit.
 *
 * كان مفقوداً (انظر FIXES.md). أُعيد إنشاؤه ليطابق سكافولد Laravel القياسي.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * ══════════════════════════════════════════════════════════════════
     * **ذاكرةٌ ساكنةٌ تعبر بين الاختبارات — و`RefreshDatabase` لا تراها.**
     *
     * `PlanCapability::$memo` تحفظ قراراتِ مصفوفة الباقات مرّةً واحدةً
     * لكلّ **عمليّة**، لا لكلّ طلب. وفي الإنتاج هذا سليم: كلُّ طلبٍ في
     * FPM عمليّةٌ جديدة، وكلُّ كتابةٍ من اللوحة تنادي
     * `EntitlementService::forget()` فتُفرِّغها.
     *
     * **أمّا مجموعةُ الاختبارات فعمليّةٌ واحدةٌ طويلة.** فاختبارٌ يكتب
     * قراراً في المصفوفة — «المدير يفتح قدرةً في باقةٍ أرخص» — يُرجَع
     * صفُّه بـ`RefreshDatabase` **وتبقى نسختُه في الذاكرة الساكنة**،
     * فيقرؤها كلُّ ما بعده.
     *
     * وهذا ما جعل `WholesaleAccessPolicyTest` يمرّ وحدَه ويسقط في
     * الجولة الكاملة: باقةٌ مجّانيّةٌ تُفتح لها قدرةٌ لم يفتحها أحد.
     *
     * **وهو أسوأُ من سقوطٍ صريح**: نتيجةٌ تتغيّر بترتيب التشغيل تُقرأ
     * «عطلٌ متقطّع»، فيُطارَد ما لا وجودَ له — وقد أُهدرت جولةٌ كاملةٌ
     * عليه. فتُفرَّغ الذاكرةُ مع القاعدة، لا بعدها.
     * ══════════════════════════════════════════════════════════════════
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Access\PlanCapability::flush();
    }

    /**
     * ══════════════════════════════════════════════════════════════════
     * **طلبُ دفعٍ مدفوعٌ حقيقيّ — لا نصٌّ يدّعي الدفع.**
     *
     * أضاف `61c0507` («fix: guard pharmacy vertical and verified QR
     * sales») بوّابةً واحدةً لكلّ بيعٍ بـ«أميال باي»:
     * `MerchantPaymentReferenceService` تشترط أن يكون المرجعُ **طلبَ QR
     * مكتملاً إلى محفظة المنشأة نفسِها، بالمبلغ نفسِه، غيرَ مستهلك**.
     *
     * **والبوّابةُ صحيحةٌ وثقبٌ ماليٌّ سُدّ بها**: بدونها يرسل التطبيقُ
     * أيَّ نصٍّ في `paid_transaction_id` فيُقيَّد البيعُ مدفوعاً بلا
     * ريالٍ تحرّك. وهي مقصورةٌ على `amial_pay` والجزء الإلكترونيّ من
     * `mixed` — **فالبيعُ النقديُّ لا تمسّه**، وقد قِيس.
     *
     * **وما سقط هو التجهيزات لا الشيفرة**: عشراتُ اختباراتٍ تمرّر
     * `paidTransactionId: 'TX12345'` بلا طلبٍ مقابل، فكانت تُثبت أنّ
     * البيعَ يُقبل بمرجعٍ مخترَع — **أي أنّها كانت تحرس الثقب**.
     *
     * فيُصنع الطلبُ هنا مرّةً واحدةً، ولا يُلَيَّن الحاجز.
     * ══════════════════════════════════════════════════════════════════
     */
    protected function paidQrRequest(
        \App\Models\User $merchant,
        string $transactionId,
        string $amount,
    ): \App\Models\PaymentRequest {
        return \App\Models\PaymentRequest::create([
            'request_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'short_code' => strtoupper(\Illuminate\Support\Str::random(8)),
            'requester_user_id' => $merchant->id,
            'amount' => \App\Services\MoneyService::normalize($amount),
            'share_method' => \App\Models\PaymentRequest::SHARE_QR,
            'status' => 'paid',
            'paid_transaction_id' => $transactionId,
            'paid_at' => now(),
            'expires_at' => now()->addDay(),
            'zone_code' => 'SOUTH',
        ]);
    }
}
