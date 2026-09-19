<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * AMIAL-OPERATIONAL-GOV-POLICY-001
 *
 * نطاق التشغيل قرار متغير، لكنه لا يجوز أن يبقى في env بلا تاريخ أو صاحب قرار.
 * يحتفظ env بدور fallback فقط؛ النسخة النشطة هنا تصبح مصدر التشغيل بعد الهجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_governorate_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->json('governorate_codes');
            $table->string('reason', 500);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('effective_at')->useCurrent();
            $table->timestamps();

            $table->index(['is_active', 'version']);
        });

        $codes = array_values(array_unique(array_filter(
            (array) config('amial.operational_governorates', [])
        )));

        DB::table('operational_governorate_policies')->insert([
            'version' => 1,
            'governorate_codes' => json_encode($codes, JSON_UNESCAPED_UNICODE),
            'reason' => 'استيراد نطاق التشغيل الحالي من إعداد الخادم عند تفعيل السياسة المرقمة',
            'created_by_admin_id' => null,
            'is_active' => true,
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_governorate_policies');
    }
};
