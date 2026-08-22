<?php

/**
 * AMIAL-ZONE-PROVENANCE-001 — **من أين جاء هذا النطاق، وهل يخالف الوثيقة؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:** `zone_assignment_logs` تسجّل `method` بثلاث قيم —
 * `registration` · `kyc_verification` · `admin_decision` — **ولا تسجّل
 * شيئاً عن التعارض**.
 *
 * و`assignByAdmin` تحفظ `old_zone` في `signals`. **وهذا ليس ما تطلبه
 * الوثيقة**: «عند Manual Override، لا تمسح حقيقة KYC الأصلية».
 *
 * **والفرقُ جوهريّ:** `old_zone` هو ما كان مكتوباً قبل لحظةٍ — وقد يكون
 * `UNKNOWN` من التسجيل، أو نطاقاً من تجاوزٍ سابق. أمّا **«ما تقوله
 * الوثيقة»** فشيءٌ آخر: النطاقُ المشتقُّ من محافظة السكن الموثَّقة.
 *
 * فحين يُسند موظّفٌ نطاقاً يخالف الوثيقة، **لا شيءَ في أيّ صفٍّ يقول
 * إنّه خالفها**. ومن يقرأ الحسابَ بعد شهرٍ يرى `NORTH` ولا يعرف أهو من
 * هويّةٍ موثّقة أم من قرارِ موظّفٍ ضدَّ هويّةٍ تقول `SOUTH`.
 *
 * **والنطاقُ يحكم حركةَ المال** (`EnforceZonePolicy`) — فتعارضٌ مكتومٌ
 * ها هنا هو بابُ تحايلٍ لا خطأُ تصنيف.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ أعمدةٍ لا واحد، لأنّ كلَّاً منها يُجيب سؤالاً مختلفاً:**
 *
 *   · `is_override`      — أخالف هذا الإسنادُ مصدراً أقوى؟ (سؤالُ نعم/لا،
 *                          يُصفّى عليه في لحظة)
 *   · `overrides_source` — **أيَّ مصدرٍ خالف** — فمخالفةُ تسجيلٍ مبدئيٍّ
 *                          ليست مخالفةَ وثيقةٍ موثّقة
 *   · `kyc_zone`         — **حقيقةُ الوثيقة محفوظةً كما هي**، فلا تُمحى
 *                          ولا يُعاد اشتقاقُها لاحقاً من بياناتٍ قد تكون
 *                          تغيّرت هي الأخرى
 *
 * ولا يُخترَع رقمُ ثقة. `confidence` **تعدادٌ من ثلاث** لكلٍّ قاعدةٌ
 * مكتوبةٌ في `ZoneAssignmentService::CONFIDENCE` — ورقمٌ مئويٌّ مخترَعٌ
 * يُقرأ قياساً وليس قياساً.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zone_assignment_logs')) {
            return;
        }

        Schema::table('zone_assignment_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('zone_assignment_logs', 'is_override')) {
                $table->boolean('is_override')->default(false)->after('method');
            }

            if (! Schema::hasColumn('zone_assignment_logs', 'overrides_source')) {
                $table->string('overrides_source', 32)->nullable()->after('is_override');
            }

            if (! Schema::hasColumn('zone_assignment_logs', 'kyc_zone')) {
                $table->string('kyc_zone', 16)->nullable()->after('overrides_source');
            }

            if (! Schema::hasColumn('zone_assignment_logs', 'confidence')) {
                $table->string('confidence', 8)->nullable()->after('kyc_zone');
            }
        });

        // **والصفوفُ القديمة تبقى `null` ولا تُملأ بالتخمين.**
        //
        // فـ`is_override = false` على صفٍّ لم يُقَس تُقرأ «فُحص فلم يُخالف»،
        // وهي القاعدة السابعة: «غير معروف» ليس صفراً. والعمودُ
        // `confidence` يبقى فارغاً على القديم، وتقوله الشاشةُ صراحةً.
        //
        // أمّا `is_override` فله قيمةٌ افتراضيّةٌ في المخطَّط لأنّ العمودَ
        // منطقيّ — ويُميَّز القديمُ بأنّ `confidence` فيه `null`.
    }

    public function down(): void
    {
        if (! Schema::hasTable('zone_assignment_logs')) {
            return;
        }

        Schema::table('zone_assignment_logs', function (Blueprint $table) {
            foreach (['is_override', 'overrides_source', 'kyc_zone', 'confidence'] as $col) {
                if (Schema::hasColumn('zone_assignment_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
