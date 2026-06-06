<?php

/**
 * AMIAL-FEE-ENGINE-001 — محرّك الرسوم المركزي
 *
 * لوحة تحكم لضبط نسب الأرباح/الرسوم لكل عملية مالية من مكان واحد،
 * بدل الدوال المبعثرة (get_sendmoney_charge, get_cashout_charge, ...).
 *
 * المبدأ:
 *   - النسخ append-only: لا نعدّل نسخة قديمة. ننشئ نسخة جديدة (version+1)
 *     تُلغي السابقة. هذا يطابق فلسفة الوثيقة: "لا تعديل للقيد القديم؛
 *     التصحيح بقيد عكسي". كل عملية تاريخية تبقى مفسَّرة بالنسخة التي
 *     استُخدمت فعلاً وقت تنفيذها (snapshot).
 *   - كل قيمة مالية DECIMAL (لا float) — يطابق transactions DECIMAL(20,4).
 *   - النسب DECIMAL(8,4): تكفي 0.0000 .. 100.0000 بدقة 4 خانات.
 *
 * مرتبط بـ:
 *   - قسم 23 معيار 5: "كل عملية مالية لديها audit أو log decision"
 *   - استراتيجية الربح: عمولات التحويل/السحب، نسبة التاجر، حصة الوكيل.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1) fee_schemes — نسخة رسم واحدة لعملية + منطقة + جهة تطبيق
        // ============================================================
        Schema::create('fee_schemes', function (Blueprint $table) {
            $table->id();

            // كود العملية: SEND_MONEY, CASH_OUT, CASH_IN, MERCHANT_QR,
            // MERCHANT_POS, SAFE_PAYMENT, BILL_PAY, SPLIT_BILL, REFUND,
            // FAMILY_FUND_CONTRIB
            $table->string('code', 48);
            $table->string('label', 120)->nullable();

            // المنطقة — SOUTH في v1، قابل للتوسعة لاحقاً
            $table->string('zone_code', 16)->default('SOUTH');

            // على من يُطبّق: customer | merchant | agent
            $table->string('applies_to', 16)->default('customer');

            // نوع الرسم: percent | fixed | percent_plus_fixed
            $table->string('fee_type', 24)->default('percent');

            // النسبة المئوية (مثال 1.5000 = 1.5%)
            $table->decimal('percent_rate', 8, 4)->default(0);
            // الجزء الثابت
            $table->decimal('fixed_amount', 20, 4)->default(0);

            // الحدّان (cap) — اختياري
            $table->decimal('min_fee', 20, 4)->nullable();
            $table->decimal('max_fee', 20, 4)->nullable();

            // حصة الوكيل من الرسم (cash_in/cash_out) — نسبة + ثابت
            $table->decimal('agent_commission_percent', 8, 4)->default(0);
            $table->decimal('agent_commission_fixed', 20, 4)->default(0);

            // من يتحمّل الرسم: sender | receiver | merchant
            $table->string('bearer', 16)->default('sender');

            // التأريخ
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable(); // admin user id

            $table->timestamps();

            // بحث سريع عن النسخة النشطة لعملية معينة
            $table->index(['code', 'zone_code', 'applies_to', 'is_active'], 'fee_active_lookup');
            $table->index(['code', 'version']);
        });

        // ============================================================
        // 2) fee_change_logs — سجل تدقيق كل تغيير (append-only)
        // ============================================================
        Schema::create('fee_change_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fee_scheme_id')->nullable();
            $table->string('code', 48);
            // created | activated | deactivated
            $table->string('action', 24);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('code');
            $table->index('fee_scheme_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_change_logs');
        Schema::dropIfExists('fee_schemes');
    }
};
