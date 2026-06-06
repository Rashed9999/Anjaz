<?php

/**
 * AMIAL-CUSTOMER-WITHDRAW-001 — السحب المبدوء من العميل.
 *
 * العميل يطلب السحب من جهازه → يُحجز المبلغ فوراً → عملية "قيد المعالجة"
 * برقم عملية ومؤقّت انتهاء → الوكيل يُدخل الهوية + رقم العملية → تنفيذ.
 * هذا يلغي الحاجة لـ SMS OTP، ويمنع حصاد الرموز عند الوكيل، ويثبت موافقة العميل.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->string('op_code', 16)->unique();            // رقم العملية (يُدخله الوكيل)
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->unsignedBigInteger('agent_user_id')->nullable()->index(); // يُملأ عند التنفيذ

            $table->decimal('amount', 20, 4);             // النقد الذي يستلمه العميل
            $table->decimal('fee', 20, 4)->default(0);
            $table->decimal('agent_commission', 20, 4)->default(0);
            $table->decimal('platform_profit', 20, 4)->default(0);
            $table->decimal('total_debit', 20, 4);        // المحجوز = amount + fee

            $table->unsignedBigInteger('fee_scheme_id')->nullable();
            $table->unsignedInteger('fee_scheme_version')->nullable();

            // pending | completed | cancelled | expired
            $table->string('status', 12)->default('pending')->index();
            $table->string('transaction_id', 40)->nullable(); // عند الإنجاز
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['customer_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
