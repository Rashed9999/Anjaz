<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٠ — المبيعةُ تعرف ورديّتَها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كانت أرقامُ الوردية تُجمَع بنافذةٍ زمنيّة:
 *
 *     FuelSale::where('station_id', …)->where('created_at', '>=', $opened_at)
 *
 * **فبيعةٌ تقع بين ورديّتين يتيمة**: بعد إغلاق الأولى وقبل `opened_at`
 * الثانية، فلا تدخل في أيٍّ منهما — **نقدٌ في الدرج بلا وردية**، يظهر
 * عجزاً في أوّل جردٍ بلا تفسير.
 *
 * والانتماءُ يُكتب لحظةَ البيع لا يُستنتج بعده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->after('station_id');

            // فهرسٌ مركّب: كلُّ استعلامات الإغلاق والتقارير «ورديّةٌ + حالة».
            $table->index(['shift_id', 'status'], 'fuel_sales_shift_status_idx');

            $table->foreign('shift_id')->references('id')->on('fuel_shifts')
                ->nullOnDelete();
        });

        // ── الصفوفُ التاريخيّة ──────────────────────────────────────────
        //
        // **تُنسب بالنافذة الزمنيّة — مرّةً واحدةً وإلى الأبد.**
        //
        // فالنافذةُ كانت الحقيقةَ الوحيدة المتاحة لها، ونسبتُها الآن تُثبّت
        // ما كان يُحسب في كلّ إغلاق. والحدُّ الأعلى `closed_at` يمنع ابتلاعَ
        // ما وقع بعد الإغلاق — وهو ما كان الاستعلامُ القديم يفعله.
        //
        // وما لا يقع داخل أيّ ورديّة **يبقى `null` ولا يُلحق بأقربها**:
        // «غير معروف» ليس صفراً، ويتيمٌ ظاهرٌ خيرٌ من يتيمٍ منسوبٍ خطأً.
        if (! Schema::hasTable('fuel_shifts')) {
            return;
        }

        DB::table('fuel_shifts')->orderBy('id')->chunk(200, function ($shifts) {
            foreach ($shifts as $shift) {
                $q = DB::table('fuel_sales')
                    ->whereNull('shift_id')
                    ->where('station_id', $shift->station_id)
                    ->where('created_at', '>=', $shift->opened_at);

                if ($shift->closed_at !== null) {
                    $q->where('created_at', '<=', $shift->closed_at);
                }

                $q->update(['shift_id' => $shift->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropIndex('fuel_sales_shift_status_idx');
            $table->dropColumn('shift_id');
        });
    }
};
