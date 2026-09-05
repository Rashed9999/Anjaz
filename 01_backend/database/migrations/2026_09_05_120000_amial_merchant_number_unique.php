<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MERCHANT-NUMBER-001 — **الفرادةُ تُحرَس في القاعدة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان `merchant_number` مشتقّاً من `user->id`، ففرادتُه مضمونةٌ بالاشتقاق
 * وحدَه **ولا قيدَ في الجدول**. وقد صار عشوائيّاً، فالاشتقاقُ ذهب ولم
 * يبقَ ما يمنع رقمين متطابقين.
 *
 * **ورقمان متطابقان لتاجرين يعني أنّ زبوناً يدفع للخطأ منهما** — ورقمُ
 * التاجر عنوانُ دفعٍ لا معرّفٌ داخليّ.
 *
 * و«اقرأ ثمّ اكتب» في الشيفرة لا يكفي: عمليّتان متزامنتان تقرآن الرقمَ
 * نفسَه فتجدانه حرّاً. **والقيدُ الفريدُ وحدَه ذرّيّ** — وهو درسٌ دُفع
 * ثمنُه هنا مرّتين (`firstOrCreate` و`USER_WALLET_{id}`).
 *
 * **و`NULL` يتكرّر تحت القيد الفريد في MySQL** — فالمتاجرُ التي لا رقمَ
 * لها لا تتعارض، وهو المطلوب: القائمون لا يُمسّون.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchants') || ! Schema::hasColumn('merchants', 'merchant_number')) {
            return;
        }

        // **ولا يُفرَض قيدٌ على جدولٍ فيه تكرارٌ قائم** — الهجرةُ تسقط
        // في منتصف النشر وتترك القاعدةَ نصفَ مهاجَرة. فيُقاس أوّلاً،
        // ويُقال ما وُجد بدل أن يُكسَر النشر.
        $dupes = DB::table('merchants')
            ->select('merchant_number', DB::raw('COUNT(*) c'))
            ->whereNotNull('merchant_number')
            ->where('merchant_number', '!=', '')
            ->groupBy('merchant_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('c', 'merchant_number');

        if ($dupes->isNotEmpty()) {
            throw new RuntimeException(
                'أرقامُ تجّارٍ مكرَّرةٌ في الجدول، فلا يُفرَض القيد: '
                .$dupes->keys()->implode('، ')
                .' — أصلِحها يدويّاً ثمّ أعِد الهجرة.');
        }

        Schema::table('merchants', function (Blueprint $table) {
            $table->unique('merchant_number', 'merchants_merchant_number_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->dropUnique('merchants_merchant_number_unique');
            });
        }
    }
};
