<?php

/**
 * CRITICAL-001-USAGE — جدول العدّادات الشهرية للحدود.
 *
 * لماذا جدول مُستقلّ ولا نعتمد على COUNT(*) من transactions؟
 *   1. الأداء: COUNT(*) على ملايين الصفوف بطيء.
 *   2. الدقّة: كل increment ذرّي (atomic) — لا race condition.
 *   3. المرونة: يدعم counter_types متعدّدة بدون تغيير schema.
 *
 * البنية:
 *   - سجل واحد لكل (merchant_user_id, period_key, counter_type).
 *   - period_key = "YYYY-MM" للحدود الشهرية، "YYYY" للحدود السنوية لاحقاً.
 *   - counter_type: 'sale_operation', 'invoice_creation', ...
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_usage_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('counter_type', 32);        // 'sale_operation', 'invoice_creation', etc
            $table->string('period_key', 16);          // "2026-06" أو "2026"
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamp('last_incremented_at')->nullable();
            $table->timestamps();

            // فهرس فريد لمنع التكرار + تسريع upsert
            $table->unique(
                ['merchant_user_id', 'counter_type', 'period_key'],
                'muc_unique'
            );
            // فهرس للقراءات السريعة (dashboard)
            $table->index(['merchant_user_id', 'period_key'], 'muc_merchant_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_usage_counters');
    }
};
