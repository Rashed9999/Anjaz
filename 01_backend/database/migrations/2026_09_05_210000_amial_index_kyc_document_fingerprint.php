<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-REUSE-001 — **البصمةُ تُحسَب منذ اليوم الأوّل، ولا قارئَ لها.**
 *
 * `KycDocumentService` يكتب `content_sha256` عند كلّ رفع — **وقِيس
 * بالبحث فلم يقرأها أحد**. أي أنّ صورةَ هويّةٍ واحدةً تُرفَع لعددٍ غير
 * محدودٍ من الحسابات ولا يلاحظ أحد.
 *
 * **والفهرسُ شرطُ أن يُقرأ:** المقارنةُ تقع في كلّ فتحِ ملفٍّ في طابور
 * المراجعة، ومسحٌ كاملٌ للجدول عليها يجعل الشاشةَ تثقُل ثمّ يُطفَأ الفحص
 * — فيُقرأ الغيابُ سلامةً وهو تعطيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kyc_documents')
            || ! Schema::hasColumn('kyc_documents', 'content_sha256')) {
            return;
        }

        // **ولا يُعاد إنشاؤه إن وُجد** — الهجرةُ تُعاد على خوادمَ مختلفة.
        $exists = collect(\DB::select('SHOW INDEX FROM kyc_documents'))
            ->contains(fn ($i) => $i->Key_name === 'kyc_docs_fingerprint_idx');

        if ($exists) {
            return;
        }

        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->index(['content_sha256'], 'kyc_docs_fingerprint_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kyc_documents')) {
            return;
        }

        $exists = collect(\DB::select('SHOW INDEX FROM kyc_documents'))
            ->contains(fn ($i) => $i->Key_name === 'kyc_docs_fingerprint_idx');

        if ($exists) {
            Schema::table('kyc_documents', function (Blueprint $table) {
                $table->dropIndex('kyc_docs_fingerprint_idx');
            });
        }
    }
};
