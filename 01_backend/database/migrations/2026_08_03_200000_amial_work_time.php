<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-WORKTIME-001 — ساعاتُ العمل: سجلٌّ لا يُعدَّل بعد كتابته.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **«غير قابل للتلاعب» ليست صفةً واحدة — هي ثلاثةُ تهديداتٍ مختلفة.**
 *
 * ١. **تلاعبٌ بالإدخال:** الموظّف يُرسل وقتاً من متصفّحه.
 *    الدفاع: لا يُقبل وقتٌ من الطلب إطلاقاً. كلّ ختمٍ من ساعة الخادم.
 *
 * ٢. **تلاعبٌ بالسلوك:** يفتح ورديّته الفجر ويغلقها منتصف الليل وهو لم
 *    يعمل ستّ ساعات. لا يُخالف قاعدةً — يستغلّ غيابها.
 *    الدفاع: سقفٌ للورديّة، واحتسابُ فترات الخمول على حدةٍ لا ابتلاعُها.
 *
 * ٣. **تلاعبٌ بالبيانات:** يُعدَّل الصفّ في القاعدة بعد كتابته.
 *    الدفاع: **سلسلةُ تجزئة**. كلّ حدثٍ يحمل بصمة ما قبله، فتعديلُ صفٍّ
 *    واحد يكسر السلسلة كلّها بعده — ويُكشَف بأمرٍ واحد.
 *
 * والثالث هو ما لم يكن له دفاعٌ في المشروع. ومن يملك الوصول إلى القاعدة
 * كان يستطيع أن يزيد ساعاتِ من يشاء بلا أثر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا جدولُ أحداثٍ لا عمودُ ساعاتٍ في `agent_shifts`؟**
 *
 * لأنّ العمود يُخزَّن مرّةً ويُقرأ ألفاً — فيصير الرقمُ حقيقةً بديلة عن
 * الواقع (القاعدة السادسة). والأحداث تُروى ولا تُلخَّص: فتحٌ، واستراحة،
 * وعودة، وإغلاق. والمجموع يُحسب من مصدره في كلّ مرّة.
 *
 * وتُضاف الاستراحة لأنّ من غيرها لا خيار للموظّف إلّا إغلاق ورديّته —
 * وإغلاقُها يعني جردَ درجٍ كاملاً لأجل نصف ساعة غداء.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_work_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_user_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('shift_id')->nullable();

            // shift_open | shift_close | break_start | break_end
            $table->string('event', 24);

            // **ختمُ الخادم وحده.** ولا عمود لوقتٍ يأتي من المتصفّح — وما
            // لا يُقبل من الطلب لا يحتاج فحصاً (القاعدة الثامنة).
            $table->timestamp('occurred_at');

            // من تسبّب بالحدث: الموظّف نفسه، أم مديرٌ أغلق عنه، أم النظام.
            $table->string('source', 16)->default('portal');
            $table->unsignedBigInteger('actor_staff_id')->nullable();

            $table->json('meta')->nullable();

            // ── سلسلة التجزئة ────────────────────────────────────────
            //
            // `hash = sha256(prev_hash + الحقول الجوهرية)`. وتعديلُ أيّ
            // حقلٍ يغيّر `hash`، فتنكسر مطابقةُ `prev_hash` في الصفّ
            // التالي وكلِّ ما بعده. ولا يُصلَح إلّا بإعادة كتابة السلسلة
            // كلّها — وهو ما يُكشف بأنّ آخر بصمةٍ لم تعد تطابق المحفوظ.
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);

            $table->timestamps();

            $table->index(['staff_id', 'occurred_at'], 'awe_staff_time_idx');
            $table->index(['agent_user_id', 'occurred_at'], 'awe_agent_time_idx');
            $table->index('shift_id', 'awe_shift_idx');
        });

        Schema::table('agent_staff', function (Blueprint $table) {
            // ساعاتُ الدوام المتوقَّعة — وصفرٌ يعني «غير مضبوط» لا «صفر
            // ساعات»: من لم يُضبط دوامُه لا يُحتسب له وقتٌ إضافيّ أصلاً،
            // لأنّ «الإضافيّ» يُقاس على متوقَّعٍ معلوم.
            $table->decimal('daily_hours_expected', 5, 2)->default(0)->after('daily_count_limit');
            $table->decimal('weekly_hours_expected', 6, 2)->default(0)->after('daily_hours_expected');

            // no | auto | approved
            //   no       = لا وقت إضافيّ لهذا الموظّف
            //   auto     = يُحتسب بمجرّد وقوعه
            //   approved = لا يُحتسب إلّا بموافقةٍ مسبقة من مديره
            $table->string('overtime_policy', 12)->default('approved')->after('weekly_hours_expected');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_work_events');

        Schema::table('agent_staff', function (Blueprint $table) {
            $table->dropColumn(['daily_hours_expected', 'weekly_hours_expected', 'overtime_policy']);
        });
    }
};
