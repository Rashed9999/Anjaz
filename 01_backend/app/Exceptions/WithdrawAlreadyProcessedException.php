<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * AMIAL-WITHDRAW-DOUBLE-001 — طلبُ سحبٍ بُتّ فيه، ومحاولةٌ ثانيةٌ تقع عليه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا صنفٌ خاصٌّ لا `RuntimeException`:**
 *
 * أوّلُ نسخةٍ رمت `RuntimeException` والتقطَتها بـ`catch (\RuntimeException)`.
 * فابتلع الالتقاطُ **كلَّ** خطأٍ من هذا الجنس داخل المعاملة — ومنه سقوطُ
 * ترحيلِ القيد — وقال للموظّف «هذا الطلب بُتّ فيه».
 *
 * والنتيجةُ رسالةٌ تكذب: الطلبُ لم يُبتّ فيه، والمالُ لم يتحرّك، والسببُ
 * الحقيقيُّ ابتُلع. وقِيس فعلاً: كان الرفضُ لا يُنفَّذ والرسالةُ تقول إنّه
 * نُفّذ من قبل.
 *
 * (ودرسُ `CLAUDE.md`: «حارسٌ يكذب أسوأ من غيابه — يُرسل من يصدّقه خلف
 * عطلٍ لا وجود له».)
 */
class WithdrawAlreadyProcessedException extends RuntimeException
{
    public function __construct(string $message = 'WITHDRAW_ALREADY_PROCESSED')
    {
        parent::__construct($message);
    }
}
