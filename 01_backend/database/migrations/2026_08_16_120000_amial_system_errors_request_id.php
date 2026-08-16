<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROD-READINESS-005 — معرّفُ الطلب في مركز الأعطال.
 *
 * بلا هذا العمود يبقى الخيطُ مقطوعاً عند آخر حلقة: العطلُ مسجَّلٌ،
 * والسجلُّ يحمل المعرّف، **والجدولُ الذي يقرؤه المشرفُ لا يحمله** — فلا
 * يستطيع أن ينتقل من صفٍّ في اللوحة إلى أسطر السجلّ التي تخصّه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_errors', function (Blueprint $t) {
            // آخرُ طلبٍ وقع فيه — لا أوّلُه: من يحقّق يبدأ من الأحدث.
            $t->string('request_id', 36)->nullable()->after('path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('system_errors', function (Blueprint $t) {
            $t->dropColumn('request_id');
        });
    }
};
