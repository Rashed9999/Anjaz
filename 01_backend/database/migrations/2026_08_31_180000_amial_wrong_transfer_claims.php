<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-WRONG-TRANSFER-001 — **حوّل إلى الرقم الخطأ. ماذا الآن؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال كما وصل:** «أحمد حوّل ١٠٠ ألفٍ فأخطأ في الرقم فوصلت سالماً.
 * كيف يسترجعها؟ ولنزد التعقيد: سالمٌ صرفها عند التجّار».
 *
 * **وقِيس ما كان:** آليّةُ نزاعٍ موجودةٌ تنقل المالَ بقيدٍ مزدوجٍ وتدقيق
 * — **وثلاثةُ ثقوبٍ فيها**:
 *
 * ① **لا زرَّ يوقف النزيف.** التجميدُ يضبط `is_temp_blocked` فيمنع
 *    **دخولاً جديداً** ولا يُبطل جلسةً مفتوحة، **و`assertFinancial
 *    Eligibility` لا تقرؤه إطلاقاً**. فسالمٌ وتطبيقُه مفتوحٌ يواصل
 *    الشراء. والعلَمُ الوحيدُ الذي يحترمه مسارُ المال
 *    (`security_hold_until`) لا يضبطه الدعمُ من أيّ شاشة.
 * ② **وإن صُرِف المالُ سقط الاستردادُ باستثناء** — والنزاعُ **كان قد
 *    حُفظ «disputed»** قبله بلا `try/catch`. فالملفُّ يقول «حُلّ»، ولا
 *    ريالَ تحرّك. **سجلٌّ ماليٌّ يكذب.**
 * ③ **ولا استردادَ جزئيّ ولا ذمّة.** صرف ٦٠ وبقي ٤٠ ⇒ لا يُسترَدّ شيء،
 *    ولا يُسجَّل في أيّ موضعٍ أنّ على سالمٍ مئةَ ألف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والمبدأ: يُحجَز المبلغُ ولا يُجمَّد الحساب.**
 *
 * تجميدُ حساب سالمٍ كلِّه على **دعوى** يعاقب بريئاً قبل التحقيق، ويُغري
 * كاذباً بأن يؤذي غريماً. أمّا حجزُ **المبلغ بعينه** من `pending_balance`
 * فيوقف النزيفَ ولا يمسّ باقي ماله.
 *
 * **والإفراجُ التلقائيُّ شرطٌ لا زينة** — حجزٌ بلا نهايةٍ يصير عقوبةً
 * بيد الدعم. فلكلّ حجزٍ ساعةُ انتهاء، ومن لم يُحسَم يُفرَج عنه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وصفٌّ واحدٌ لا جدولان.** الدعوى والحجزُ والذمّةُ أطوارُ حادثةٍ واحدة،
 * وتفريقُها على جداولَ يجعل «كم بقي على سالم؟» سؤالاً بجوابين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wrong_transfer_claims', function (Blueprint $t) {
            $t->id();
            $t->string('claim_ulid', 32)->unique();

            // **العمليّةُ الأصليّة** — مرجعُها النصّيّ لا مفتاحُها الرقميّ:
            // فالتتبّعُ كلُّه في المشروع يجري على `transaction_id`.
            $t->string('transaction_id', 64)->index();

            $t->unsignedBigInteger('claimant_user_id')->index();   // أحمد
            $t->unsignedBigInteger('recipient_user_id')->index();  // سالم
            $t->decimal('amount', 20, 4);

            // **ما يقول أحمدُ إنّه قصده** — وهو أهمُّ إشارةٍ في التقدير:
            // فرقُ رقمٍ أو رقمين خطأُ إصبع، وفرقُ خمسةٍ اختيار.
            $t->string('claimed_intended_phone', 32)->nullable();

            $t->string('status', 24)->default('open')->index();

            $t->decimal('held_amount', 20, 4)->default(0);
            $t->decimal('recovered_amount', 20, 4)->default(0);

            // **الذمّةُ لا الرصيدُ السالب.** «لا رصيدَ سالب» ثابتٌ يفحصه
            // ضغطُ البوّابة، وكسرُه يفتح باباً في كلّ مسارٍ ماليّ لا في
            // هذا وحدَه. فيبقى الرصيدُ موجباً وتُسجَّل الذمّةُ هنا.
            $t->decimal('receivable_amount', 20, 4)->default(0);
            $t->decimal('receivable_settled', 20, 4)->default(0);

            // تقديرُ الدعوى — **درجةٌ لا حكم**. والقرارُ للإنسان، لكنّه
            // يقرأ رقماً لا حدساً.
            $t->unsignedTinyInteger('risk_score')->default(0);
            $t->json('risk_signals')->nullable();

            $t->timestamp('hold_expires_at')->nullable()->index();

            $t->unsignedBigInteger('opened_by')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('resolution_note', 500)->nullable();
            $t->timestamp('resolved_at')->nullable();

            $t->timestamps();

        });

        // ══════════════════════════════════════════════════════════════
        // **دعوى واحدةٌ حيّةٌ لكلّ عمليّة — بعمودٍ محسوبٍ لا بقيدٍ مركّب.**
        //
        // أوّلُ صياغةٍ كتبت `unique(transaction_id, status)` — **وهي
        // خطأ**: تمنع دعويين **مرفوضتين** على العمليّة نفسِها، وتلك
        // حالةٌ مشروعةٌ تماماً (يُدّعى ويُرفَض ثمّ يُدّعى بدليلٍ جديد).
        //
        // فالمقصودُ **الحيّةُ وحدَها**: `open` و`holding`. وما سواهما
        // تاريخٌ لا يتزاحم. والنمطُ هو نفسُه في `fee_schemes.active_key`
        // ولا يُخترَع ثانٍ.
        //
        // ولولا هذا القيد لفُتحت دعويان على عمليّةٍ واحدة **فحُجز المبلغُ
        // مرّتين** — أي ضعفُ ما استُلم، من مالٍ لا علاقةَ له بالحادثة.
        // ══════════════════════════════════════════════════════════════
        try {
            DB::statement(
                'ALTER TABLE wrong_transfer_claims ADD COLUMN live_key VARCHAR(72) AS ('
                ."IF(status IN ('open','holding'), transaction_id, NULL)"
                .') STORED');
            DB::statement(
                'ALTER TABLE wrong_transfer_claims ADD UNIQUE INDEX wtc_one_live (live_key)');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'AMIAL-WRONG-TRANSFER-001: تعذّر قيدُ «دعوى حيّةٌ واحدة» — '
                .'فقد تُفتَح دعويان على عمليّةٍ واحدةٍ ويُحجَز المبلغُ مرّتين. '
                .$e->getMessage());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wrong_transfer_claims');
    }
};
