<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SAHER-RULING-001 — **فرزٌ مبنيٌّ ولا يُوصَل إليه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *   · `FindingStore::HUMAN_HELD` = ['FALSE_POSITIVE','ACCEPTED_RISK','SUPPRESSED']
 *     — وهي حالاتٌ **تنجو من كلّ مسح**، مبنيّةٌ ومُختبَرة
 *   · والشاشةُ تترجمها: «إيجابيّةٌ كاذبة» · «مخاطرةٌ مقبولة» · «مكتوم»
 *   · والصلاحيّتان `saher.findings.suppress` و`.acknowledge` معرَّفتان
 *     في هجرةٍ منذ يوم
 *   · **ولا مسارَ ولا زرَّ ولا دالّةَ تضع أيّاً منها**
 *
 * فالفرزُ كلُّه جاهزٌ ولا يُوصَل إليه — **النمطُ نفسُه، واقعاً داخل
 * الأداة التي بُنيت لكشفه**.
 *
 * وثمنُه مقيس: ٩٦ نتيجةً قائمةً، منها `releaseHold` **متروكةٌ عمداً**
 * (نسخةٌ صارمةٌ، والتحريرُ يقع بـ`releaseHoldUpTo`، والتعليقُ يقوله).
 * ولا سبيلَ لتسجيل ذلك — **فكلُّ تدقيقٍ يُعيد قراءتها من الصفر، ويعود
 * إغراءُ الحذف الجَماعيّ**. وحذفُها يمحو سجلَّ ما ينقص.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والحكمُ ينتهي — وإلّا صار صمتاً مؤجَّلاً.**
 *
 * الدرسُ مدفوعٌ في هذا المشروع: `gradle-floor-waiver.json` يذكر الحدَّين
 * معاً فينتهي من تلقائه أوّلَ ما يتحرّك أحدُهما. **وإقرارٌ لا ينتهي
 * صمتٌ مؤجَّل.**
 *
 * وهنا: بصمةُ `Finding` هي `ruleId|assetKey` — **ثابتةٌ عبر إعادة كتابة
 * الدالّة كلِّها**. فحكمٌ صدر على شيفرةٍ يبقى ساريّاً على خَلَفٍ لم يره
 * أحد. ولذلك يُحفَظ **تجزيءُ الملفّ المُصرِّح وقتَ الحكم**: إن تغيّر،
 * رُفع الحكمُ وعاد الاكتشافُ مفتوحاً بسببٍ مكتوب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saher_findings', function (Blueprint $t) {
            // **والسببُ نصٌّ لا علامة.** حكمٌ بلا سببٍ يُقرأ بعد شهرين
            // «أحدُهم رآه ولم يقل لماذا» — وهو أسوأ من قائمةٍ مفتوحة.
            $t->text('ruling_reason')->nullable()->after('resolved_at');
            $t->unsignedBigInteger('ruling_by_user_id')->nullable()->after('ruling_reason');
            $t->timestamp('ruled_at')->nullable()->after('ruling_by_user_id');

            // **وتجزيءُ الملفّ وقتَ الحكم — به ينتهي الحكم.**
            $t->string('ruling_source_hash', 64)->nullable()->after('ruled_at');
        });
    }

    public function down(): void
    {
        Schema::table('saher_findings', function (Blueprint $t) {
            $t->dropColumn(['ruling_reason', 'ruling_by_user_id', 'ruled_at', 'ruling_source_hash']);
        });
    }
};
