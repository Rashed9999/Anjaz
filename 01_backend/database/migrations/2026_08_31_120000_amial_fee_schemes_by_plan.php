<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-FEE-PLAN-001 — **الرسمُ يعرف الباقة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلب كما وصل:** «أردتُ عملَ رسومٍ على باقة البداية ٠٫٢٪ — هل سوف
 * تُطبَّق؟». وقِيس: **لا**. أبعادُ الرسم كانت ثلاثةً —
 * `code × zone_code × applies_to` — **ولا ذكرَ للباقة في المحرّك ولا في
 * النموذج ولا في سياسة الخصم**. فحقلٌ يُضاف في الشاشة كان سيتجاهله
 * المحرّك، وهي أسوأُ من غيابه: يضبط الأدمنُ سعراً ويراه فعّالاً ولا
 * يُخصَم منه ريال.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقرار: تخصيصٌ لا أولويّةٌ اعتباطيّة.**
 *
 * سُئل «أتُقدَّم الباقةُ على المنطقة أم العكس؟» — **والسؤالُ لا محلَّ له**:
 * `zone_code` **مطابقةٌ تامّةٌ في كلّ صفٍّ اليوم** (لا صفَّ «كلُّ
 * المناطق»)، فلا منافسةَ بينهما أصلاً. والمحورُ الجديدُ وحدَه هو الباقة،
 * والحكمُ فيه واضح:
 *
 *   `plan = 'starter'`  →  تسري على «البداية» وحدَها
 *   `plan = NULL`       →  **كلُّ الباقات** — وهي ما كان قائماً قبل اليوم
 *
 * **والأخصُّ يفوز.** فمن ضبط سعراً عامّاً ثمّ استثنى باقةً، عمل
 * الاستثناءُ ولم يُلغِ العامَّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والمفتاحُ الفريدُ يُوسَّع معه — وإلّا لم تُبنَ الميزةُ أصلاً.**
 *
 * `active_key` عمودٌ محسوبٌ يمنع أكثرَ من نسخةٍ نشطةٍ لِـ
 * `(code, zone, applies_to)`. فبلا توسيعه **يمتنع أن توجد نسخةٌ عامّةٌ
 * ونسخةُ باقةٍ نشطتين معاً** — وهو عينُ ما تقوم عليه الطبقة. فيصير
 * المفتاحُ رباعيّاً بـ`COALESCE(plan,'*')`.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا صفَّ قائمٌ يتغيّر سلوكُه.** العمودُ `NULL` على كلّ ما هو موجود،
 * و`NULL` تعني «كلُّ الباقات» — أي **نفسَ ما كانت تفعله قبل الهجرة
 * بالضبط**. فالترقيةُ لا تُحرّك سعراً واحداً.
 */
return new class extends Migration
{
    private const KEY_WITH_PLAN =
        "IF(is_active = 1, CONCAT(code, ':', zone_code, ':', applies_to, ':', COALESCE(plan, '*')), NULL)";

    private const KEY_WITHOUT =
        "IF(is_active = 1, CONCAT(code, ':', zone_code, ':', applies_to), NULL)";

    public function up(): void
    {
        if (! Schema::hasColumn('fee_schemes', 'plan')) {
            Schema::table('fee_schemes', function (Blueprint $t) {
                // **`nullable` عمداً** — و`NULL` تعني «كلُّ الباقات».
                // ولا قيمةَ افتراضيّةٌ نصّيّة: `'all'` تبدو صريحةً وتُفسد
                // الترتيبَ (‏تصير باقةً اسمُها «all» تنافس الباقاتِ
                // الحقيقيّة)، و`NULL` تقولها في لغة القاعدة نفسِها.
                $t->string('plan', 24)->nullable()->after('applies_to');

                // فهرسُ البحث يشمله — والاستعلامُ يقرأ الباقةَ في كلّ نداء.
                $t->index(['code', 'zone_code', 'applies_to', 'plan', 'is_active'],
                    'fee_plan_lookup');
            });
        }

        // **والمفتاحُ الفريدُ يُعاد بناؤه.** ولو تعذّر بقي القيدُ القديم
        // ساريّاً — فتُمنع النسختان، **ويُقال ذلك ولا يُسكت عنه**.
        $this->rebuildActiveKey(self::KEY_WITH_PLAN);

        // ══════════════════════════════════════════════════════════════
        // **وقيدٌ ثانٍ يجب أن يتّسع معه — وقد كُشف بالتشغيل لا بالقراءة.**
        //
        // `fee_one_version` فريدٌ على `(code, zone, applies_to, version)`.
        // فنسخةٌ عامّةٌ v1 ونسخةُ باقةٍ v1 **تتصادمان**، والخطأُ يخرج
        // `1062 Duplicate entry` غامضاً في وجه الأدمن عند أوّل حفظ.
        //
        // وترقيمُ النسخ يبقى **لكلّ باقةٍ على حدة** — وهو الصواب: تاريخُ
        // سعر «البداية» مستقلٌّ عن تاريخ السعر العامّ، وخلطُهما يجعل
        // «النسخة ٣» لا تدلّ على شيء.
        // ══════════════════════════════════════════════════════════════
        $this->rebuildVersionKey(true);
    }

    public function down(): void
    {
        // **الصفوفُ المُخصَّصةُ بباقةٍ تُعطَّل قبل النزع.**
        //
        // فلو بقيت نشطةً وضاق المفتاحُ لاصطدمت بنسخةٍ عامّةٍ لها نفسُ
        // (‏الرمز · المنطقة · الجهة) — **فتفشل الهجرةُ العكسيّةُ في
        // منتصفها** وتترك الجدولَ نصفَ مُرقّى. والتعطيلُ يحفظ الصفَّ
        // وتاريخَه ولا يحذفه.
        DB::table('fee_schemes')->whereNotNull('plan')->update(['is_active' => 0]);

        $this->rebuildVersionKey(false);
        $this->rebuildActiveKey(self::KEY_WITHOUT);

        if (Schema::hasColumn('fee_schemes', 'plan')) {
            Schema::table('fee_schemes', function (Blueprint $t) {
                $t->dropIndex('fee_plan_lookup');
                $t->dropColumn('plan');
            });
        }
    }

    /**
     * قيدُ «رقمُ نسخةٍ واحدٌ لا يتكرّر» — **بعمودٍ محسوبٍ لا بأعمدةٍ خام.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا صوابٌ اشتُري بانحدارٍ أحدثتُه ثمّ أمسكه حارسٌ قائم.**
     *
     * أوّلُ صياغةٍ وسّعت الفهرسَ إلى `(code, zone, applies_to, plan,
     * version)` — **وفي MySQL لا يتصادم `NULL` مع `NULL` في فهرسٍ
     * فريد**. وكلُّ صفٍّ قائمٍ اليوم `plan = NULL`، فبطل القيدُ على
     * الجميع دفعةً واحدة: يُقبل رقما نسخةٍ متساويان، و
     * `orderByDesc('version')->first()` يصير عشوائيّاً — **أي أنّ
     * «أيُّ تسعيرةٍ تسري؟» يفقد جوابَه الواحد**.
     *
     * فأسقطه `FeeCentreScreensGuardTest::the_database_refuses_a_
     * duplicate_version_number` في أوّل تشغيلٍ للبوّابة.
     *
     * **والعلاجُ عمودٌ محسوبٌ بـ`COALESCE(plan,'*')`** — كما فُعل في
     * `active_key` بالضبط: فالفراغُ يصير نصّاً يتصادم مع مثله.
     * ══════════════════════════════════════════════════════════════════
     */
    private function rebuildVersionKey(bool $withPlan): void
    {
        try {
            DB::statement('ALTER TABLE fee_schemes DROP INDEX fee_one_version');
        } catch (\Throwable) {
            // غيرُ موجود — لا شيء يُنزع.
        }

        try {
            if (Schema::hasColumn('fee_schemes', 'version_key')) {
                DB::statement('ALTER TABLE fee_schemes DROP COLUMN version_key');
            }
        } catch (\Throwable) {
            // لم يُنشأ بعد.
        }

        if (! $withPlan) {
            try {
                DB::statement('ALTER TABLE fee_schemes ADD UNIQUE INDEX fee_one_version '
                    .'(code, zone_code, applies_to, version)');
            } catch (\Throwable) {
                // موجودٌ سلفاً.
            }

            return;
        }

        try {
            DB::statement(
                'ALTER TABLE fee_schemes ADD COLUMN version_key VARCHAR(160) AS ('
                ."CONCAT(code, ':', zone_code, ':', applies_to, ':', COALESCE(plan, '*'), ':', version)"
                .') STORED');
            DB::statement(
                'ALTER TABLE fee_schemes ADD UNIQUE INDEX fee_one_version (version_key)');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'AMIAL-FEE-PLAN-001: تعذّر بناءُ قيد رقم النسخة — '
                .'فقد يتكرّر رقمُ نسخةٍ ويصير الترتيبُ عشوائيّاً. '.$e->getMessage());
        }
    }

    /** يُعيد بناءَ العمود المحسوب وقيدَه — ويقول إن تعذّر. */
    private function rebuildActiveKey(string $expression): void
    {
        try {
            if (Schema::hasColumn('fee_schemes', 'active_key')) {
                DB::statement('ALTER TABLE fee_schemes DROP INDEX fee_one_active');
                DB::statement('ALTER TABLE fee_schemes DROP COLUMN active_key');
            }

            DB::statement(
                "ALTER TABLE fee_schemes ADD COLUMN active_key VARCHAR(140) AS ({$expression}) STORED");
            DB::statement(
                'ALTER TABLE fee_schemes ADD UNIQUE INDEX fee_one_active (active_key)');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'AMIAL-FEE-PLAN-001: تعذّر توسيعُ مفتاح «نسخةٌ نشطةٌ واحدة» — '
                .'فتبقى نسخةُ الباقة ونسخةُ «كلّ الباقات» متعارضتين في القاعدة، '
                .'ويبقى الترتيبُ محروساً في الخدمة وحدَها. '.$e->getMessage());
        }
    }
};
