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

        $ledger = app(LedgerService::class);

        $account = $ledger->getOrCreateUserWallet(
            (int) $wallet->user_id,
            (string) ($wallet->zone_code ?? 'SOUTH'),
        );

        $opening = $ledger->getOrCreateSystemAccount(
            'OPENING_BALANCE',
            'equity',
            'أرصدة افتتاحية (محافظ وُلدت مموَّلة)',
            'debit',
        );

        $ledger->post(
            sourceType: 'opening_balance',
            sourceId: (string) $wallet->user_id,
            description: "رصيد افتتاحي لمحفظة المستخدم {$wallet->user_id}",
            lines: [
                ['account' => $opening->account_code, 'direction' => 'debit', 'amount' => $balance],
                ['account' => $account->account_code, 'direction' => 'credit', 'amount' => $balance],
            ],
            idempotencyKey: "opening_wallet_{$wallet->user_id}",
            // حساب الافتتاح حقوق ملكية ويصير سالباً بالتعريف: هو مصدر المال
            // الذي وُجد قبل الدفتر، لا رصيدٌ يُصان.
            allowNegative: true,
            zoneCode: (string) ($wallet->zone_code ?? 'SOUTH'),
        );
    }
}
