<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-INSTALLMENTS-001 — البيع بالتقسيط (تمويل مرابحة من المحفظة).
 *   installment_plans     — شروط التاجر (دفعة أولى/مدد/هامش/رسوم تأخير/ضمانات).
 *   installment_contracts — عقد تقسيم لكل عملية بيع مموّلة.
 *   installment_schedules — جدول الأقساط الشهرية باستحقاقاتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id')->unique();
            $t->boolean('is_active')->default(false);
            $t->decimal('min_amount', 14, 2)->default('0');
            $t->decimal('max_amount', 14, 2)->default('0');            // سقف التمويل (ضمان)
            $t->decimal('down_payment_percent', 5, 2)->default('25');  // الدفعة الأولى (ضمان جدّية)
            $t->json('durations')->nullable();                          // [3,6,12] المدد المتاحة
            $t->decimal('markup_percent', 6, 2)->default('0');         // هامش/رسوم إجمالية على المموّل
            $t->decimal('late_fee_percent', 5, 2)->default('0');       // رسم تأخير على القسط
            $t->unsignedInteger('grace_days')->default(3);             // أيام سماح قبل رسم التأخير
            $t->boolean('require_kyc')->default(true);                  // ضمان الهوية (KYC)
            $t->boolean('require_guarantor')->default(false);          // ضمان الكفيل
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
        });

        Schema::create('installment_contracts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->unsignedBigInteger('customer_user_id');
            $t->unsignedBigInteger('guarantor_user_id')->nullable();
            $t->string('sale_ulid', 40)->nullable();
            $t->string('item_name')->nullable();
            $t->decimal('principal', 14, 2);          // سعر السلعة
            $t->decimal('down_payment', 14, 2);        // الدفعة الأولى (حُصّلت فوراً)
            $t->decimal('financed_amount', 14, 2);     // المبلغ المموّل = السعر - الدفعة
            $t->decimal('markup_amount', 14, 2)->default('0');
            $t->decimal('total_payable', 14, 2);       // المموّل + الهامش
            $t->unsignedInteger('months');
            $t->decimal('monthly_amount', 14, 2);
            $t->decimal('paid_amount', 14, 2)->default('0');   // ما سُدّد من الأقساط
            $t->decimal('late_fees_total', 14, 2)->default('0');
            $t->enum('status', ['active', 'completed', 'defaulted', 'cancelled'])->default('active');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->index('merchant_user_id', 'inst_ctr_merchant_idx');
            $t->index('customer_user_id', 'inst_ctr_customer_idx');
        });

        Schema::create('installment_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contract_id');
            $t->unsignedInteger('seq');
            $t->date('due_date');
            $t->decimal('amount', 14, 2);
            $t->decimal('paid_amount', 14, 2)->default('0');
            $t->decimal('late_fee', 14, 2)->default('0');
            $t->enum('status', ['due', 'paid', 'overdue'])->default('due');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
            $t->index('contract_id', 'inst_sch_contract_idx');
            $t->unique(['contract_id', 'seq'], 'inst_sch_contract_seq_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_schedules');
        Schema::dropIfExists('installment_contracts');
        Schema::dropIfExists('installment_plans');
    }
};
