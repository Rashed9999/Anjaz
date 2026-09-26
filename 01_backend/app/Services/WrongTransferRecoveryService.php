<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\WrongTransferClaim;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-WRONG-TRANSFER-001 — **حوّل إلى الرقم الخطأ. ماذا الآن؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال كما وصل:** «أحمد حوّل ١٠٠ ألفٍ فأخطأ رقمَ الهاتف فوصلت
 * سالماً. ولنزد التعقيد: سالمٌ صرفها عند التجّار».
 *
 * **والجواب في أربع حركات، لا واحدة:**
 *
 *   ① **يُحجَز الموجودُ فوراً** — `holdUpTo` تأخذ ما وُجد ولا ترمي على
 *      النقص. فمن أنفق ٦٠ من ١٠٠ تُحجَز أربعونه، لا صفر.
 *   ② **وتُسجَّل الذمّةُ بالباقي** — ولا يُصنَع رصيدٌ سالب. («لا رصيدَ
 *      سالب» ثابتٌ يفحصه ضغطُ البوّابة، وكسرُه هنا يفتح باباً في كلّ
 *      مسارٍ ماليّ لا في هذا وحدَه.)
 *   ③ **ويُفرَج تلقائيّاً بعد المهلة** — حجزٌ بلا نهايةٍ عقوبةٌ بيد
 *      الدعم، ودعوى بلا قرارٍ ليست إدانة.
 *   ④ **وتُقاس الدعوى قبل أن تُحجَز** — فمن يحفظ أموالَ الناس يجب أن
 *      يحسب حسابَ المحتالين: بلا تقديرٍ يصير هذا البابُ سلاحاً بيد من
 *      يشتري ثمّ يدّعي الخطأ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ حدودٍ مكتوبةٍ لا مسكوتٌ عنها:**
 *
 * **أوّلاً — الشراءُ من تاجرٍ ليس تحويلاً خاطئاً.** من دفع لتاجرٍ ثمّ
 * ندم يستردّ **من التاجر** بمسار `MerchantSaleRefundService`. ولو فُتح
 * هذا البابُ على مدفوعات التجّار لصار كلُّ زبونٍ نادمٍ يسحب مالَه من
 * صندوق تاجرٍ سلّم بضاعتَه. فتُرفَض `PAYMENT` و`merchant_payment` صراحةً.
 *
 * **ثانياً — يُحجَز المبلغُ ولا يُجمَّد الحساب.** تجميدُ حساب سالمٍ كلِّه
 * على **دعوى** يعاقب بريئاً قبل التحقيق. والحجزُ يوقف النزيفَ ولا يمسّ
 * باقي ماله.
 *
 * **ثالثاً — لا يُسأل `assertFinancialEligibility` عن المستلِم.** وهي
 * تُسأل في كلّ مسارٍ ماليٍّ آخر، والاستثناءُ هنا مقصود: أن نمتنع عن
 * **إعادة** المال لأنّ حساب آخذِه محظور، عقوبةٌ تقع على المجنيّ عليه.
 * والاستردادُ حركةُ إصلاحٍ لا خدمةٌ تُقدَّم للمستلِم.
 */
class WrongTransferRecoveryService
{
    // **`TransactionTrait` وحدَها — وهي تضمّ `PostsToLedger` في داخلها.**
    // وضمُّ الاثنتين هنا يُسقط الصنفَ بتصادم `ledgerService`، وهو خطأٌ
    // قاتلٌ عند التحميل لا عند التشغيل: لا يظهر في أيّ سجلٍّ حتّى يُنادى.
    use TransactionTrait;

    /** المهلةُ الافتراضيّةُ للحجز — ثلاثةُ أيّامٍ عملٍ تقريباً. */
    public const HOLD_HOURS = 72;

    /** أنواعُ العمليّات التي يجوز فتحُ دعوى عليها. */
    public const CLAIMABLE = [SEND_MONEY];

    /** ما يُرفَض صراحةً — ولكلٍّ مسارُ استردادٍ خاصٌّ به. */
    public const NOT_CLAIMABLE = [PAYMENT, 'merchant_payment'];

    /** أكثرُ ما يُسمَح به من دعاوى حيّةٍ لمدّعٍ واحد. */
    public const MAX_LIVE_PER_CLAIMANT = 3;

    /** عددُ الرفوضات في تسعين يوماً الذي يُوقف الحجزَ التلقائيّ. */
    public const REJECTED_BLOCKS_AUTO_HOLD = 2;

    /** فوق هذه الدرجة لا يُحجَز تلقائيّاً — تُفتح الدعوى وتنتظر إنساناً. */
    public const SCORE_BLOCKS_AUTO_HOLD = 70;

    // ═════════════════════════════════════════════════════════════════
    // ① الفتحُ والحجز
    // ═════════════════════════════════════════════════════════════════

    /**
     * يفتح دعوى ويحجز ما وُجد من المبلغ لدى المستلِم.
     *
     * @throws \RuntimeException برسالةٍ عربيّةٍ تقول **السبب**، لا «خطأ ما».
     */
    public function open(
        string $transactionId,
        ?string $intendedPhone = null,
        ?int $openedBy = null,
    ): WrongTransferClaim {
        $tx = Transaction::where('transaction_id', $transactionId)->first();

        if (! $tx) {
            throw new \RuntimeException('العمليّةُ غيرُ موجودة: '.$transactionId);
        }

        if (in_array($tx->transaction_type, self::NOT_CLAIMABLE, true)) {
            throw new \RuntimeException(
                'هذه عمليّةُ دفعٍ لتاجر، لا تحويلاً إلى رقمٍ خاطئ. '
                .'واستردادُها يكون من التاجر عبر «مرتجَعات المبيعات» — '
                .'فسحبُ المال من صندوق تاجرٍ سلّم بضاعتَه ليس استرداداً.');
        }

        if (! in_array($tx->transaction_type, self::CLAIMABLE, true)) {
            throw new \RuntimeException(
                'نوعُ العمليّة «'.$tx->transaction_type.'» لا تُفتح عليه دعوى '
                .'تحويلٍ خاطئ — الدعوى للتحويل بين العملاء وحدَه.');
        }

        $claimantId = (int) $tx->from_user_id;
        $recipientId = (int) $tx->to_user_id;

        if (! $claimantId || ! $recipientId) {
            throw new \RuntimeException('العمليّةُ بلا طرفين واضحين — لا يُعرف من يطالب ومن يُطالَب.');
        }

        if ($claimantId === $recipientId) {
            throw new \RuntimeException('المُرسِلُ والمستلِمُ واحد — لا تحويلَ خاطئاً هنا.');
        }

        // **دعوى واحدةٌ حيّةٌ لكلّ عمليّة** — والقاعدةُ تحرسه أيضاً بقيدٍ
        // فريد، وهذا الفحصُ ليقولَ السببَ بالعربيّة قبل أن يرمي المحرّك
        // `1062 Duplicate entry` في وجه موظّف الدعم.
        $live = WrongTransferClaim::where('transaction_id', $transactionId)
            ->whereIn('status', WrongTransferClaim::LIVE)->first();

        if ($live) {
            throw new \RuntimeException(
                'توجد دعوى مفتوحةٌ على هذه العمليّة برقم '.$live->claim_ulid.'.');
        }

        // **وما حُسم لا يُعاد فتحُه** — ولا يُحجَز المبلغُ مرّتين.
        if (WrongTransferClaim::where('transaction_id', $transactionId)
            ->where('status', WrongTransferClaim::RECOVERED)->exists()) {
            throw new \RuntimeException('استُرِدَّت هذه العمليّةُ سلفاً.');
        }

        $liveCount = WrongTransferClaim::where('claimant_user_id', $claimantId)
            ->whereIn('status', WrongTransferClaim::LIVE)->count();

        if ($liveCount >= self::MAX_LIVE_PER_CLAIMANT) {
            throw new \RuntimeException(
                'لهذا العميل '.$liveCount.' دعاوى مفتوحةٌ بالفعل — والحدُّ '
                .self::MAX_LIVE_PER_CLAIMANT.'. تُحسَم القائمةُ أوّلاً.');
        }

        $amount = MoneyService::normalize($tx->amount ?? $tx->debit ?? '0');

        if (! MoneyService::isPositive($amount)) {
            throw new \RuntimeException('مبلغُ العمليّة صفرٌ أو غيرُ صالح.');
        }

        $assessment = $this->score($tx, $claimantId, $recipientId, $intendedPhone);

        return DB::transaction(function () use (
            $tx, $transactionId, $claimantId, $recipientId,
            $amount, $intendedPhone, $openedBy, $assessment
        ) {
            $claim = WrongTransferClaim::create([
                'claim_ulid' => (string) Str::ulid(),
                'transaction_id' => $transactionId,
                'claimant_user_id' => $claimantId,
                'recipient_user_id' => $recipientId,
                'amount' => $amount,
                'claimed_intended_phone' => $intendedPhone,
                'status' => WrongTransferClaim::OPEN,
                'risk_score' => $assessment['score'],
                'risk_signals' => $assessment['signals'],
                'opened_by' => $openedBy,
                'hold_expires_at' => now()->addHours(self::HOLD_HOURS),
            ]);

            // ══════════════════════════════════════════════════════════
            // **والحجزُ التلقائيُّ يُوقَف عند الشكّ — ولا تُغلَق الدعوى.**
            //
            // فمن ادّعى وقُدِّرت دعواه عاليةَ الخطورة تُفتَح دعواه ويُقرأ
            // سببُها، **ولا يُنتزَع مالُ الطرف الآخر بضغطة**. والفرقُ بين
            // «لا تُحجَز» و«تُرفَض» جوهريّ: الأولى تُبقي الملفَّ حيّاً
            // أمام إنسانٍ يقرّر، والثانيةُ تُغلق البابَ على شكوى قد تصدق.
            // ══════════════════════════════════════════════════════════
            if ($assessment['auto_hold']) {
                $held = $this->guard()->holdUpTo(
                    $recipientId, $amount, 'wrong_transfer_claim:'.$claim->claim_ulid);

                if (MoneyService::isPositive($held)) {
                    $claim->held_amount = $held;
                    $claim->status = WrongTransferClaim::HOLDING;
                    $claim->save();
                }
            }

            $this->audit()->record([
                'actor_type' => $openedBy ? 'admin' : 'system',
                'actor_user_id' => $openedBy,
                'subject_type' => 'wrong_transfer_claim',
                'subject_id' => $claim->claim_ulid,
                'action' => 'WRONG_TRANSFER_CLAIM_OPENED',
                'decision_code' => $assessment['auto_hold'] ? 'CLAIM_HELD' : 'CLAIM_NEEDS_REVIEW',
                'transaction_id' => $transactionId,
                'severity' => 'notice',
                'context' => [
                    'claimant_user_id' => $claimantId,
                    'recipient_user_id' => $recipientId,
                    'amount' => $amount,
                    'held_amount' => (string) $claim->held_amount,
                    'risk_score' => $assessment['score'],
                    'risk_signals' => $assessment['signals'],
                ],
            ]);

            return $claim->refresh();
        }, 3);
    }

    // ═════════════════════════════════════════════════════════════════
    // ② التقدير
    // ═════════════════════════════════════════════════════════════════

    /**
     * **درجةٌ لا حكم.** يُعيد `score` (٠–١٠٠، الأعلى أكثرُ ريبةً)
     * و`signals` مقروءةً بالعربيّة و`auto_hold`.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولمَ تُقاس أصلاً:** «حسابُ المحتالين» ليس تشدّداً على المظلوم —
     * هو شرطُ بقاء البابِ مفتوحاً له. فبابُ استردادٍ بلا تقديرٍ يُستعمَل
     * مرّتين ثمّ يُغلَق على الجميع.
     *
     * **وكلُّ إشارةٍ تُقاس من مصدرها لا تُخمَّن** (القاعدة السادسة)،
     * **وما لا يُعرَف يُقال «غيرُ معروف» ولا يُقرأ صفراً** (السابعة):
     * فمن لم يذكر الرقمَ المقصود لا تُحسَب له براءةُ «خطأِ إصبع»، ولا
     * يُدان بها.
     * ══════════════════════════════════════════════════════════════════
     */
    public function score(
        Transaction $tx,
        int $claimantId,
        int $recipientId,
        ?string $intendedPhone,
    ): array {
        $signals = [];
        $score = 0;

        // ① **مسافةُ الرقم** — أقوى إشارةٍ في الباب.
        $recipientPhone = (string) (User::where('id', $recipientId)->value('phone') ?? '');
        $intended = preg_replace('/\D+/', '', (string) $intendedPhone);
        $actual = preg_replace('/\D+/', '', $recipientPhone);

        if ($intended === '' || $actual === '') {
            $signals['phone_distance'] = 'غيرُ معروف — لم يُذكر الرقمُ المقصود';
            $score += 15;
        } else {
            $d = levenshtein(substr($intended, -9), substr($actual, -9));
            $signals['phone_distance'] = $d;

            if ($d === 0) {
                // الرقمُ المقصودُ هو المستلِمُ نفسُه — فأينَ الخطأ؟
                $signals['phone_note'] = 'الرقمُ المقصودُ يطابق المستلِم — لا خطأَ في الإدخال';
                $score += 60;
            } elseif ($d <= 2) {
                $signals['phone_note'] = 'فرقُ رقمٍ أو رقمين — خطأُ إصبعٍ مُرجَّح';
            } else {
                $signals['phone_note'] = 'فرقٌ كبيرٌ بين الرقمين — ليس زلّةَ إصبع';
                $score += 30;
            }
        }

        // ② **زمنُ البلاغ.** من انتبه بعد دقائقَ أصدقُ ممّن انتبه بعد أيّام.
        $minutes = $tx->created_at ? $tx->created_at->diffInMinutes(now()) : null;
        $signals['minutes_since_transfer'] = $minutes ?? 'غيرُ معروف';

        if ($minutes !== null) {
            if ($minutes > 60 * 24 * 7) {
                $signals['timing_note'] = 'بُلِّغ بعد أكثرَ من أسبوع';
                $score += 25;
            } elseif ($minutes > 60 * 24) {
                $signals['timing_note'] = 'بُلِّغ بعد أكثرَ من يوم';
                $score += 10;
            }
        }

        // ③ **سابقةُ التحويل إلى المستلِم نفسِه.** من حوّل إليه قبلَها
        //    مرّةً واحدةً على الأقلّ **يعرفه** — فدعوى «رقمٌ خاطئ» تضعف.
        $priorCount = Transaction::where('from_user_id', $claimantId)
            ->where('to_user_id', $recipientId)
            ->where('transaction_id', '!=', $tx->transaction_id)
            ->where('created_at', '<', $tx->created_at ?? now())
            ->count();

        $signals['prior_transfers_to_recipient'] = $priorCount;

        if ($priorCount > 0) {
            $signals['prior_note'] = 'حوّل إلى هذا الرقم من قبل — يعرفه';
            $score += 35;
        }

        // ④ **أهو في المفضّلة؟** رقمٌ حفظه بنفسِه ليس رقماً أخطأ فيه.
        $isFavourite = DB::table('favourite_numbers')
            ->where('user_id', $claimantId)
            ->where(function ($q) use ($recipientPhone) {
                $q->where('value', $recipientPhone)->orWhere('phone', $recipientPhone);
            })->exists();

        $signals['recipient_is_favourite'] = $isFavourite;

        if ($isFavourite) {
            $signals['favourite_note'] = 'الرقمُ محفوظٌ في مفضّلة المُرسِل';
            $score += 30;
        }

        // ⑤ **سجلُّ المدّعي.** من رُفضت دعواه مرّتين في تسعين يوماً
        //    لا تُصدَّق ثالثتُه تلقائيّاً — **وتُقرأ ولا تُرفَض سلفاً**.
        $rejected = WrongTransferClaim::where('claimant_user_id', $claimantId)
            ->where('status', WrongTransferClaim::REJECTED)
            ->where('created_at', '>=', now()->subDays(90))
            ->count();

        $signals['rejected_claims_90d'] = $rejected;

        if ($rejected > 0) {
            $score += min(40, $rejected * 20);
            $signals['history_note'] = $rejected.' دعوى مرفوضةٌ في تسعين يوماً';
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'signals' => $signals,
            'auto_hold' => $score < self::SCORE_BLOCKS_AUTO_HOLD
                && $rejected < self::REJECTED_BLOCKS_AUTO_HOLD,
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    // ③ الحسمُ — استردادٌ كاملٌ أو جزئيٌّ مع ذمّة
    // ═════════════════════════════════════════════════════════════════

    /**
     * يحسم الدعوى لصالح المدّعي: يُصرَف المحجوزُ إليه، ويُسجَّل الباقي ذمّةً.
     *
     * **ولا يُحفَظ القرارُ خارج المعاملة.** كان في مسار النزاعات القديم
     * `$dispute->save()` قبل حركة المال بلا `try/catch`؛ فإن سقطت الحركة
     * بقي الملفُّ يقول «حُلّ» ولا ريالَ تحرّك. **وسجلٌّ ماليٌّ يكذب أسوأ
     * من سجلٍّ لا يوجد.**
     */
    public function resolve(WrongTransferClaim $claim, ?int $adminId = null, string $note = ''): WrongTransferClaim
    {
        if (! in_array($claim->status, WrongTransferClaim::LIVE, true)) {
            throw new \RuntimeException('الدعوى محسومةٌ سلفاً ('.$claim->status.').');
        }

        return DB::transaction(function () use ($claim, $adminId, $note) {
            $claim = WrongTransferClaim::whereKey($claim->id)->lockForUpdate()->first();

            if (! in_array($claim->status, WrongTransferClaim::LIVE, true)) {
                throw new \RuntimeException('الدعوى حُسمت في هذه اللحظة من مسارٍ آخر.');
            }

            $held = MoneyService::normalize($claim->held_amount);

            if (MoneyService::isPositive($held)) {
                $this->moveHeldToClaimant($claim, $held);
                $claim->recovered_amount = MoneyService::add($claim->recovered_amount, $held);
                $claim->held_amount = MoneyService::normalize('0');
            }

            // **الفارقُ ذمّةٌ لا رصيدٌ سالب.**
            $outstanding = MoneyService::sub($claim->amount, $claim->recovered_amount);
            $claim->receivable_amount = MoneyService::compare($outstanding, '0') > 0
                ? $outstanding : MoneyService::normalize('0');

            $claim->status = WrongTransferClaim::RECOVERED;
            $claim->resolved_by = $adminId;
            $claim->resolution_note = $note !== '' ? $note : null;
            $claim->resolved_at = now();
            $claim->hold_expires_at = null;
            $claim->save();

            $this->audit()->record([
                'actor_type' => $adminId ? 'admin' : 'system',
                'actor_user_id' => $adminId,
                'subject_type' => 'wrong_transfer_claim',
                'subject_id' => $claim->claim_ulid,
                'action' => 'WRONG_TRANSFER_CLAIM_RESOLVED',
                'decision_code' => MoneyService::isPositive($claim->receivable_amount)
                    ? 'RECOVERED_PARTIAL' : 'RECOVERED_FULL',
                'transaction_id' => $claim->transaction_id,
                'severity' => 'notice',
                'context' => [
                    'amount' => (string) $claim->amount,
                    'recovered' => (string) $claim->recovered_amount,
                    'receivable' => (string) $claim->receivable_amount,
                    'note' => $note,
                ],
            ]);

            return $claim;
        }, 3);
    }

    /** يرفض الدعوى ويُفرِج عن المحجوز — **والإفراجُ جزءٌ من الرفض لا خطوةٌ بعده**. */
    public function reject(WrongTransferClaim $claim, ?int $adminId = null, string $note = ''): WrongTransferClaim
    {
        if (! in_array($claim->status, WrongTransferClaim::LIVE, true)) {
            throw new \RuntimeException('الدعوى محسومةٌ سلفاً ('.$claim->status.').');
        }

        return $this->closeAndRelease($claim, WrongTransferClaim::REJECTED, $adminId, $note,
            'WRONG_TRANSFER_CLAIM_REJECTED');
    }

    // ═════════════════════════════════════════════════════════════════
    // ④ الكنسُ — الإفراجُ التلقائيُّ وتحصيلُ الذمم
    // ═════════════════════════════════════════════════════════════════

    /**
     * يُفرِج عن كلّ حجزٍ مضت مهلتُه بلا قرار.
     *
     * **وهذا شرطُ مشروعيّة الحجز كلِّه.** حجزٌ بلا نهايةٍ ليس إجراءً
     * احترازيّاً — هو مصادرةٌ بيد الدعم. ولا يُقال «سنراجعه يدويّاً»:
     * ما لا يجري تلقائيّاً لا يجري.
     *
     * @return int عددُ الدعاوى التي انتهت مهلتُها
     */
    public function expireStale(): int
    {
        $due = WrongTransferClaim::whereIn('status', WrongTransferClaim::LIVE)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', now())
            ->get();

        $n = 0;

        foreach ($due as $claim) {
            try {
                $this->closeAndRelease($claim, WrongTransferClaim::EXPIRED, null,
                    'انقضت المهلةُ بلا قرار — أُفرِج عن الحجز تلقائيّاً.',
                    'WRONG_TRANSFER_CLAIM_EXPIRED');
                $n++;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error(
                    'AMIAL-WRONG-TRANSFER-001: تعذّر الإفراجُ عن حجز الدعوى '
                    .$claim->claim_ulid.' — '.$e->getMessage());
            }
        }

        return $n;
    }

    /**
     * **يقتصّ الذممَ من الوارد.** لكلّ دعوى بقيت لها ذمّةٌ، إن صار لدى
     * المستلِم رصيدٌ يُقتطَع منه ما أمكن ويُحوَّل إلى المدّعي.
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا هو جوابُ «صرفها عند التجّار» كاملاً.** الحجزُ يمسك ما بقي،
     * والذمّةُ تمسك ما ذهب — فالمالُ يعود على دفعاتٍ كلّما دخل حسابَه
     * ريال، ولا يضيع لأنّه أُنفق قبل البلاغ.
     *
     * **ولا يُقتطَع من محجوزٍ لدعوى أخرى**: الاقتطاعُ من
     * `current_balance` وحدَه، و`held_balance` خارجَه بحكم بنية المحفظة.
     * ══════════════════════════════════════════════════════════════════
     *
     * @return string إجماليُّ ما حُصِّل في هذه الجولة
     */
    public function collectReceivables(): string
    {
        $collected = MoneyService::normalize('0');

        $open = WrongTransferClaim::where('status', WrongTransferClaim::RECOVERED)
            ->whereColumn('receivable_settled', '<', 'receivable_amount')
            ->orderBy('id')
            ->get();

        // ══════════════════════════════════════════════════════════════
        // **ولا `try/catch` حول الاقتطاع — عن قصدٍ وبعد سقوطِ حارس.**
        //
        // أوّلُ صياغةٍ لفّت كلَّ دعوى بـ`try { … } catch { Log::error }`
        // لئلّا توقف دعوى معطوبةٌ بقيّةَ الجولة. **وأسقطها
        // `LedgerBlockingGuardTest` في أوّل تشغيلٍ للبوّابة**: نداءُ
        // ترحيلٍ داخل `try` وcatch يكتفي بسطرٍ في السجلّ هو بعينه النمطُ
        // الذي جعل الدفترَ معطَّلاً صامتاً من قبل — المالُ يتحرّك والقيدُ
        // لا يُكتب، بلا خطأٍ ولا انهيار.
        //
        // **والعزلُ لم يكن يشتري ما ظننتُه.** معاملةُ كلّ دعوى مستقلّةٌ
        // أصلاً، فسقوطُ واحدةٍ يردّها كاملةً ولا يترك نصفَ حركة. والجولةُ
        // تتكرّر كلَّ خمس دقائق وهي ذرّيّةٌ، فما لم يُحصَّل اليوم يُحصَّل
        // في الجولة التالية. أمّا الخطأُ المكتوم فيبقى مكتوماً إلى الأبد.
        //
        // فيصعد الاستثناءُ، ويسقط الأمرُ برمزٍ غيرِ صفر، **ويُرى**.
        // ══════════════════════════════════════════════════════════════
        foreach ($open as $row) {
            $took = DB::transaction(function () use ($row) {
                $claim = WrongTransferClaim::whereKey($row->id)->lockForUpdate()->first();
                $due = $claim->outstanding();

                if (MoneyService::compare($due, '0') <= 0) {
                    return MoneyService::normalize('0');
                }

                $wallet = $this->guard()->lockWallet((int) $claim->recipient_user_id);
                $available = MoneyService::normalize($wallet->current_balance);

                if (MoneyService::compare($available, '0') <= 0) {
                    return MoneyService::normalize('0');
                }

                $take = MoneyService::compare($available, $due) >= 0 ? $due : $available;

                $this->guard()->debit((int) $claim->recipient_user_id, $take,
                    'wrong_transfer_receivable:'.$claim->claim_ulid);
                $this->guard()->credit((int) $claim->claimant_user_id, $take,
                    'wrong_transfer_receivable:'.$claim->claim_ulid);

                $this->recordBothSides($claim, $take, 'اقتطاعُ ذمّةِ تحويلٍ خاطئ');

                $this->ledgerTransfer(
                    fromUserId: (int) $claim->recipient_user_id,
                    toUserId: (int) $claim->claimant_user_id,
                    amount: $take,
                    sourceType: 'wrong_transfer_receivable',
                    sourceId: $claim->claim_ulid,
                    description: 'اقتطاعُ ذمّةِ تحويلٍ خاطئ',
                    idempotencyKey: 'wtc_recv_'.$claim->claim_ulid.'_'.$claim->receivable_settled,
                );

                $claim->receivable_settled = MoneyService::add($claim->receivable_settled, $take);
                $claim->recovered_amount = MoneyService::add($claim->recovered_amount, $take);
                $claim->save();

                $this->audit()->record([
                    'actor_type' => 'system',
                    'subject_type' => 'wrong_transfer_claim',
                    'subject_id' => $claim->claim_ulid,
                    'action' => 'WRONG_TRANSFER_RECEIVABLE_COLLECTED',
                    'decision_code' => MoneyService::compare($claim->outstanding(), '0') > 0
                        ? 'RECEIVABLE_PARTIAL' : 'RECEIVABLE_CLEARED',
                    'transaction_id' => $claim->transaction_id,
                    'severity' => 'notice',
                    'context' => [
                        'collected' => $take,
                        'still_outstanding' => $claim->outstanding(),
                    ],
                ]);

                return $take;
            }, 3);

            $collected = MoneyService::add($collected, $took);
        }

        return $collected;
    }

    // ═════════════════════════════════════════════════════════════════
    // الداخليّات
    // ═════════════════════════════════════════════════════════════════

    /** يصرف المحجوزَ من المستلِم ويُضيفه للمدّعي — بقيدٍ مزدوجٍ وسجلَّي عمليّة. */
    private function moveHeldToClaimant(WrongTransferClaim $claim, string $held): void
    {
        $this->guard()->captureHold((int) $claim->recipient_user_id, $held,
            'wrong_transfer_capture:'.$claim->claim_ulid);

        $this->guard()->credit((int) $claim->claimant_user_id, $held,
            'wrong_transfer_refund:'.$claim->claim_ulid);

        $this->recordBothSides($claim, $held, 'استردادُ تحويلٍ إلى رقمٍ خاطئ');

        $this->ledgerTransfer(
            fromUserId: (int) $claim->recipient_user_id,
            toUserId: (int) $claim->claimant_user_id,
            amount: $held,
            sourceType: 'wrong_transfer_recovery',
            sourceId: $claim->claim_ulid,
            description: 'استردادُ تحويلٍ إلى رقمٍ خاطئ',
            idempotencyKey: 'wtc_cap_'.$claim->claim_ulid,
        );
    }

    /**
     * سطرا العمليّة — **ومرجعُهما العمليّةُ الأصليّة**، فيظهر الاستردادُ
     * في تتبّع الدعم تحت العمليّة التي أخطأت لا مبتوراً عنها.
     */
    private function recordBothSides(WrongTransferClaim $claim, string $amount, string $note): void
    {
        $claimantWallet = $this->guard()->getWallet((int) $claim->claimant_user_id);
        $recipientWallet = $this->guard()->getWallet((int) $claim->recipient_user_id);

        $this->recordTransaction([
            'user_id' => (int) $claim->claimant_user_id,
            'transaction_id' => $this->newTransactionId(),
            'ref_trans_id' => $claim->transaction_id,
            'transaction_type' => ADDED_DISPUTE_MONEY,
            'debit' => '0',
            'credit' => $amount,
            'amount' => $amount,
            'balance' => (string) ($claimantWallet->current_balance ?? '0'),
            'from_user_id' => (int) $claim->recipient_user_id,
            'to_user_id' => (int) $claim->claimant_user_id,
            'note' => $note,
            'decision_code' => 'WRONG_TRANSFER_RECOVERED',
            'zone_code' => $claimantWallet->zone_code ?? 'SOUTH',
        ]);

        $this->recordTransaction([
            'user_id' => (int) $claim->recipient_user_id,
            'transaction_id' => $this->newTransactionId(),
            'ref_trans_id' => $claim->transaction_id,
            'transaction_type' => DEDUCTED_DISPUTE_MONEY,
            'debit' => $amount,
            'credit' => '0',
            'amount' => $amount,
            'balance' => (string) ($recipientWallet->current_balance ?? '0'),
            'from_user_id' => (int) $claim->recipient_user_id,
            'to_user_id' => (int) $claim->claimant_user_id,
            'note' => $note,
            'decision_code' => 'WRONG_TRANSFER_RECOVERED',
            'zone_code' => $recipientWallet->zone_code ?? 'SOUTH',
        ]);
    }

    /** يُغلق الدعوى بحالةٍ نهائيّةٍ **ويُفرِج عن محجوزها في المعاملة نفسِها**. */
    private function closeAndRelease(
        WrongTransferClaim $claim,
        string $status,
        ?int $adminId,
        string $note,
        string $action,
    ): WrongTransferClaim {
        return DB::transaction(function () use ($claim, $status, $adminId, $note, $action) {
            $claim = WrongTransferClaim::whereKey($claim->id)->lockForUpdate()->first();

            if (! in_array($claim->status, WrongTransferClaim::LIVE, true)) {
                return $claim;
            }

            $held = MoneyService::normalize($claim->held_amount);
            $released = MoneyService::normalize('0');

            if (MoneyService::isPositive($held)) {
                // **`releaseHoldUpTo` لا `releaseHold`** — فحجزٌ حُرّر من
                // مسارٍ آخر يجب ألّا يترك الدعوى عالقةً إلى الأبد.
                $released = $this->guard()->releaseHoldUpTo(
                    (int) $claim->recipient_user_id, $held,
                    'wrong_transfer_release:'.$claim->claim_ulid);
            }

            $claim->held_amount = MoneyService::normalize('0');
            $claim->status = $status;
            $claim->resolved_by = $adminId;
            $claim->resolution_note = $note !== '' ? $note : null;
            $claim->resolved_at = now();
            $claim->hold_expires_at = null;
            $claim->save();

            $this->audit()->record([
                'actor_type' => $adminId ? 'admin' : 'system',
                'actor_user_id' => $adminId,
                'subject_type' => 'wrong_transfer_claim',
                'subject_id' => $claim->claim_ulid,
                'action' => $action,
                'decision_code' => $status === WrongTransferClaim::EXPIRED
                    ? 'HOLD_EXPIRED' : 'CLAIM_REJECTED',
                'transaction_id' => $claim->transaction_id,
                'severity' => 'notice',
                'context' => [
                    'released' => $released,
                    'note' => $note,
                ],
            ]);

            return $claim;
        }, 3);
    }
}
