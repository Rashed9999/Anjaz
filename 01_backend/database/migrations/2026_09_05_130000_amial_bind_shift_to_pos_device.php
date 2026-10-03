<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SHIFT-DEVICE-001 — **الجهازُ مسجَّلٌ ومفصولٌ عن المال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قال صاحبُ المشروع:** «الموظّفين + نقاط البيع POS + الورديّات — يجب
 * أن يكون بينهم ترابطٌ متين».
 *
 * وقِيس المثلّثُ قبل أن يُكتب سطر:
 *
 *     موظّف ──✓── ورديّة   `pos_user_id` · `opened_by` · `opened_by_role`
 *     ورديّة ──✓── بيعة    `merchant_sales.shift_id`
 *     جهاز  ──✗── ورديّة   **لا عمودَ إطلاقاً**
 *     جهاز  ──✗── بيعة    **لا عمودَ إطلاقاً**
 *
 * فالجهازُ يُسجَّل ويُحدَّد بمقاعد الباقة ويُلغى عند الفقد — **ولا يعرف
 * أحدٌ كم قبض**. وأربعةُ أسئلةٍ لا جوابَ لها اليوم:
 *
 *   ① كم قبض هذا الصندوقُ اليوم؟ — لا يُحسب.
 *   ② جهازٌ فُقد وأُلغي: ورديّتُه المفتوحةُ تبقى مفتوحةً إلى الأبد،
 *      **وعهدتُها نقدٌ في يدٍ مجهولة**.
 *   ③ موظّفان يفتحان ورديّتين على **الصندوق الواحد** — لا شيءَ يمنع،
 *      ودرجُ النقد واحدٌ فالجردُ لا يُنسَب لأحد.
 *   ④ وأيُّ الأجهزة باع هذه الفاتورة؟ — لا أثر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **و`open_device_lock` ليس تكراراً لـ`pos_device_id`.**
 *
 * المطلوبُ «ورديّةٌ مفتوحةٌ واحدةٌ لكلّ جهاز»، وMySQL لا تعرف الفهرسَ
 * الفريدَ المشروط. فالعمودُ يحمل رقمَ الجهاز **ما دامت مفتوحةً** ويصير
 * `NULL` عند الإغلاق — و`NULL` يتكرّر تحت القيد الفريد. فتُحرَس الحالةُ
 * في القاعدة لا في الشيفرة.
 *
 * **و«اقرأ ثمّ اكتب» لا يكفي**: موظّفان يضغطان «افتح» في اللحظة نفسِها
 * يقرآن «لا ورديّةَ مفتوحة» كلاهما. وهو الدرسُ الذي دُفع ثمنُه في هذا
 * المشروع مرّتين.
 *
 * **ولا يُملأ للورديّات القائمة**: لا نعرف على أيّ جهازٍ فُتحت، و
 * **تخمينٌ في عهدةِ نقدٍ أسوأ من فراغٍ يُقال** (القاعدة السابعة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cashier_shifts')) {
            Schema::table('cashier_shifts', function (Blueprint $table) {
                if (! Schema::hasColumn('cashier_shifts', 'pos_device_id')) {
                    $table->unsignedBigInteger('pos_device_id')->nullable()->after('pos_user_id');
                    $table->index(['pos_device_id', 'status'], 'idx_shifts_device_status');
                }

                if (! Schema::hasColumn('cashier_shifts', 'open_device_lock')) {
                    $table->unsignedBigInteger('open_device_lock')->nullable()->after('pos_device_id');
                    $table->unique('open_device_lock', 'uniq_shift_open_device');
                }
            });
        }

        // ══════════════════════════════════════════════════════════════
        // **وحالةٌ ثالثةٌ للورديّة: `abandoned`.**
        //
        // `status` كان `enum('open','closed')`، وورديّةُ جهازٍ ملغىً
        // ليست واحدةً منهما: هي **لم تُقفَل** (لم يعدّ أحدٌ الدرج) و**لم
        // تعد مفتوحة** (الجهازُ لا يستجيب). وحشرُها في «مقفلة» يكتب
        // فرقَ جردٍ مخترَعاً في سجلٍّ ماليّ. (القاعدة السابعة.)
        //
        // وأمسك الحارسُ هذا فوراً — `Data truncated for column 'status'`
        // — لأنّ القاعدةَ ترمي. وهو الفرقُ بينه وبين `access_type` في
        // `pii_access_logs` التي تُبتلَع فتُفقَد بصمت.
        // ══════════════════════════════════════════════════════════════
        if (Schema::hasTable('cashier_shifts')) {
            DB::statement(
                "ALTER TABLE cashier_shifts MODIFY status
                 ENUM('open','closed','abandoned') NOT NULL DEFAULT 'open'");
        }

        if (Schema::hasTable('merchant_sales')
            && ! Schema::hasColumn('merchant_sales', 'pos_device_id')) {
            Schema::table('merchant_sales', function (Blueprint $table) {
                $table->unsignedBigInteger('pos_device_id')->nullable()->after('pos_user_id');
                $table->index('pos_device_id', 'idx_sales_device');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cashier_shifts')) {
            Schema::table('cashier_shifts', function (Blueprint $table) {
                if (Schema::hasColumn('cashier_shifts', 'open_device_lock')) {
                    $table->dropUnique('uniq_shift_open_device');
                    $table->dropColumn('open_device_lock');
                }
                if (Schema::hasColumn('cashier_shifts', 'pos_device_id')) {
                    $table->dropIndex('idx_shifts_device_status');
                    $table->dropColumn('pos_device_id');
                }
            });
        }

        if (Schema::hasTable('merchant_sales')
            && Schema::hasColumn('merchant_sales', 'pos_device_id')) {
            Schema::table('merchant_sales', function (Blueprint $table) {
                $table->dropIndex('idx_sales_device');
                $table->dropColumn('pos_device_id');
            });
        }
    }
};
