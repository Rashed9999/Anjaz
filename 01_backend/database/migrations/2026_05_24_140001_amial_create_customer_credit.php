<?php

/**
 * AMIAL-CUSTOMER-CREDIT-001 — نظام الديون المتقدّم (Accounts Receivable).
 *
 * يحلّ مشكلة "دفتر الديون" التقليدي للتاجر اليمني — يرقمنه بحسابات وكشوف وتصنيف.
 *
 * customer_credit_accounts:
 *   حساب ائتماني لكل (تاجر، رقم هاتف عميل). العميل قد لا يكون مستخدم أميال باي.
 *   credit_limit: الحد الأقصى المسموح للدَّيْن.
 *   current_balance: ما على العميل للتاجر الآن (موجب = عليه دين، 0 = مسدّد).
 *
 * customer_credit_movements:
 *   سجل append-only لكل تحرّك على الحساب (بيع آجل / سداد / مرتجع / تعديل).
 *   balance_after: لقطة الرصيد بعد الحركة (لكشف الحساب).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->string('customer_phone', 32);
            // إن كان العميل مستخدماً مسجّلاً في أميال باي، نربطه
            $table->unsignedBigInteger('customer_user_id')->nullable()->index();
            $table->string('customer_name', 120);

            $table->decimal('credit_limit', 20, 4)->default(0);   // 0 = بلا حد (التاجر يقرّر)
            $table->decimal('current_balance', 20, 4)->default(0); // عليه دين = موجب

            // bronze / silver / gold — يُحسب تلقائياً من السلوك
            $table->string('classification', 16)->default('bronze');
            $table->timestamp('last_payment_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            // عميل واحد لكل تاجر برقم هاتف
            $table->unique(['merchant_user_id', 'customer_phone']);
            $table->index(['merchant_user_id', 'is_active']);
        });

        Schema::create('customer_credit_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_ulid', 40)->unique();
            $table->unsignedBigInteger('account_id')->index();

            // sale | payment | return | adjustment
            $table->string('type', 16);

            // موجب: يزيد الدَّيْن (بيع آجل)؛ سالب: ينقصه (سداد، مرتجع، تعديل).
            $table->decimal('amount', 20, 4);
            $table->decimal('balance_after', 20, 4); // لقطة الرصيد بعد القيد

            // للبيع الآجل: تاريخ الاستحقاق المتفق عليه
            $table->date('due_date')->nullable();

            // الربط بمصدر القيد (merchant_sale، payment_transaction، إلخ)
            $table->string('reference_type', 32)->nullable();
            $table->string('reference_id', 40)->nullable();
            $table->string('reference_number', 32)->nullable(); // للعرض مثل #AMY-90211

            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable(); // التاجر/POS

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index(['account_id', 'type']);
            $table->index(['due_date']); // لاستعلامات المستحقّ قريباً
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_movements');
        Schema::dropIfExists('customer_credit_accounts');
    }
};
