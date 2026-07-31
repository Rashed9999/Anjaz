<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AML-SANCTION-REVIEW-001 — البتّ في المطابقة المحتملة (الفصل ١٠، التبويب ٦).
 *
 * **الفجوة:** `SanctionScreeningService` يُنتج ثلاث نتائج — `clear` و
 * `potential_match` و`confirmed_match`. والأولى والثالثة تحسمان نفسيهما:
 * لا تطابق، أو تطابقٌ يُوقف الحساب.
 *
 * أمّا **`potential_match` فلا تحسم شيئاً** — هي بالتعريف «يحتاج مراجعة
 * موظّف». وكان الجدول يُسجّلها ولا يملك حقلاً لنتيجة تلك المراجعة، فلا
 * مكان يُكتب فيه «فُحص وتبيّن أنّه شخصٌ آخر» ولا «تأكّد فأُوقف».
 *
 * ونتيجة ذلك أنّ المطابقات المحتملة كانت تتراكم بلا بتّ: العميل يُترك
 * معلّقاً بين حالتين، ومن يفتح السجلّ بعد شهر لا يعرف أنُظر فيها أم لا.
 *
 * **ولماذا `pending` هي الحالة الابتدائية لا `dismissed`:** الافتراض الآمن
 * أنّ المطابقة **لم تُراجَع**، لا أنّها استُبعدت. وسجلٌّ يبدأ مستبعَداً
 * يجعل الصمت يبدو قراراً — وهو أخطر ما في هذا الباب: تشابهُ اسمٍ مع مدرَجٍ
 * في قائمة عقوبات يُستبعَد بالفحص، لا بمرور الوقت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sanction_screening_logs', function (Blueprint $t) {
            if (!Schema::hasColumn('sanction_screening_logs', 'review_status')) {
                $t->enum('review_status', [
                    'pending',    // لم تُراجَع — الافتراض الآمن
                    'dismissed',  // فُحصت وتبيّن أنّه شخصٌ آخر
                    'confirmed',  // تأكّدت المطابقة
                ])->default('pending')->index()->after('result');
            }

            if (!Schema::hasColumn('sanction_screening_logs', 'reviewed_by')) {
                $t->unsignedBigInteger('reviewed_by')->nullable()->after('review_status');
                $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                // السبب إلزاميّ في المنطق لا في الجدول: استبعادُ مطابقةٍ مع
                // قائمة عقوبات قرارٌ يُراجَع من جهةٍ رقابية، ومن يقرؤه بعد
                // سنة لن يجد في يده غير ما كُتب هنا.
                $t->text('review_note')->nullable()->after('reviewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sanction_screening_logs', function (Blueprint $t) {
            foreach (['review_status', 'reviewed_by', 'reviewed_at', 'review_note'] as $col) {
                if (Schema::hasColumn('sanction_screening_logs', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
