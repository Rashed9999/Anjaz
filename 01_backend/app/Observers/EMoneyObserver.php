<?php

namespace App\Observers;

use App\Models\EMoney;
use App\Services\LedgerService;

/**
 * AMIAL-LEDGER-OPENING-002 — المحفظة تدخل الدفتر لحظةَ نشأتها، لا لحظةَ أوّل خصم.
 *
 * **العطل الذي يعالجه:**
 * كان `LedgerService::getOrCreateUserWallet` ينشئ حساب المحفظة برصيد صفر
 * بينما `e_money` مموَّلة من خارج الدفتر (إنشاء إداريّ، بذرة، أمر تهيئة).
 * فيُرفض أوّل خصمٍ بـ«الرصيد لا يكفي»، ويُبتلع الرفض في `safeLedgerPost`،
 * فيتحرّك المال ولا يُكتب قيدٌ إطلاقاً. قِيس ذلك حيّاً: نزل الرصيد من
 * 10000 إلى 9000 وعدد قيود `send_money` **صفر**.
 *
 * **ولماذا هنا لا في `getOrCreateUserWallet`؟**
 * جُرّب هناك أوّلاً فبدا صحيحاً وسقط: إن كانت المحفظة قد مُوّلت في العملية
 * نفسها قبل أوّل لقاءٍ بالدفتر، التقط الافتتاحُ الرصيدَ **بعد** الإضافة ثم
 * أضافت العمليةُ قيدها فوقه. قياس: شحن وكيل بـ 50000 صار في الدفتر 100000.
 *
 * ولحظة `created` بمنأى عن ذلك: صفّ المحفظة يُنشأ مرّةً واحدة، ورصيده حينها
 * هو رصيدُ ما قبل أي عملية بحكم التعريف. فالافتتاح يقع مرّة، في نقطةٍ لا
 * تتكرّر، ولا يزاحم قيدَ عمليةٍ جارية.
 *
 * **الحدّ الذي يبقى مكشوفاً — عمداً:**
 * تعديلٌ مباشر على `current_balance` بعد الإنشاء (`update`) لا يمرّ من هنا.
 * وهذا مقصود: مثلُ ذلك التعديل عطلٌ يجب أن يُرى لا أن يُداوى تلقائياً.
 * من احتاجه صراحةً (شحن إداريّ مثلاً) فليُعلنه عبر
 * `LedgerService::reconcileWalletBalance` وليسمّ سببه، ويحرسه
 * `LedgerDriftGuardTest`.
 */
class EMoneyObserver
{
    public function created(EMoney $wallet): void
    {
        $balance = (string) ($wallet->current_balance ?? '0');

        // محفظةٌ تولد فارغة تطابق حساباً بلا قيود — ولا قيد بمجموع صفر.
        // وهذه هي الحالة الغالبة في الإنتاج: `FinancialGuardService::lockWallet`
        // ينشئ المحفظة بصفر ثم تُموّلها عمليةٌ لها قيدها الخاصّ.
        if (bccomp($balance, '0', 4) <= 0) {
            return;
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-MULTI-CURRENCY-002 — **الافتتاحُ يقع في حساب عملته.**
        //
        // كان هذا المراقبُ ينادي `getOrCreateUserWallet($id, $zone)` بلا
        // عملة، ومفتاحُ منع التكرار فيه `opening_wallet_{id}` — واحدٌ لكلّ
        // **مستخدم**. فلمّا صار للمستخدم أكثرُ من محفظةٍ ظهر عطلان
        // متراكبان، **وكلاهما صامت**:
        //
        // ① **محفظةُ دولارٍ مموَّلةٍ تفتح في حساب الريال.** مئةُ دولارٍ
        //    تُقيَّد مئةَ ريالٍ في الحساب الخطأ — قيدٌ متوازنٌ يمرّ ويُقرأ.
        //
        // ② **وإن نجا من الأولى وقع في الثانية**: المفتاحُ نفسُه يجعل قيدَ
        //    المحفظة الثانية يُقرأ «مسجَّلٌ سلفاً» فيُردّ القيدُ الأوّلُ
        //    مكانَه ولا يُكتب شيء — **فمحفظةٌ فيها مالٌ بلا قيدٍ إطلاقاً**،
        //    وفرقُ مصالحةٍ دائمٌ لا يُفسَّر.
        //
        // ولا يمسك أيَّهما مُصرِّفٌ ولا مُحلِّل: النداءُ صحيحٌ نحويّاً،
        // والمعاملُ الغائبُ له افتراضيّ.
        // ══════════════════════════════════════════════════════════════
        $currency = \App\Support\Money\Currencies::normalize(
            $wallet->currency ?: \App\Support\Money\Currencies::BASE
        );
        $isBase = \App\Support\Money\Currencies::isBase($currency);

        $ledger = app(LedgerService::class);

        $account = $ledger->getOrCreateUserWallet(
            (int) $wallet->user_id,
            (string) ($wallet->zone_code ?? 'SOUTH'),
            $currency,
        );

        // **وحسابُ الافتتاح لكلّ عملةٍ حسابُه** — وإلّا خلط قيدٌ عملتين
        // فرفضه الدفترُ (وهو محقّ)، أو مرّ فصار في حسابٍ واحدٍ مجموعُ
        // دولارٍ وريالٍ وهو ليس مبلغاً من شيء.
        // والأساسُ يحتفظ برمزه `OPENING_BALANCE` — فلا تُنقَل قيودٌ قائمة.
        $opening = $ledger->getOrCreateSystemAccount(
            $isBase ? 'OPENING_BALANCE' : "OPENING_BALANCE_{$currency}",
            'equity',
            'أرصدة افتتاحية (محافظ وُلدت مموَّلة)'
                .($isBase ? '' : ' — '.\App\Support\Money\Currencies::nameAr($currency)),
            'debit',
            $currency,
        );

        $ledger->post(
            sourceType: 'opening_balance',
            sourceId: (string) $wallet->user_id,
            description: "رصيد افتتاحي لمحفظة المستخدم {$wallet->user_id}"
                .($isBase ? '' : " ({$currency})"),
            lines: [
                ['account' => $opening->account_code, 'direction' => 'debit', 'amount' => $balance],
                ['account' => $account->account_code, 'direction' => 'credit', 'amount' => $balance],
            ],
            // **والمفتاحُ يحمل العملة** — ومفتاحُ الأساس يبقى كما كان فلا
            // تُعاد كتابةُ قيودٍ قائمة.
            idempotencyKey: $isBase
                ? "opening_wallet_{$wallet->user_id}"
                : "opening_wallet_{$wallet->user_id}_{$currency}",
            // حساب الافتتاح حقوق ملكية ويصير سالباً بالتعريف: هو مصدر المال
            // الذي وُجد قبل الدفتر، لا رصيدٌ يُصان.
            allowNegative: true,
            zoneCode: (string) ($wallet->zone_code ?? 'SOUTH'),
        );
    }
}
