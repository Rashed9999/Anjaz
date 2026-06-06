<?php

/**
 * AMIAL-CASHIER-001 — كاشير خفيف داخل دور التاجر.
 *
 * فلسفة الخفّة: لا منطق مالي جديد. الدفع يعيد استخدام merchant_payment.
 * جدولان فقط:
 *   - merchant_products: كتالوج اختياري (التاجر يقدر يبيع بمبلغ حرّ بلا منتجات).
 *   - merchant_sales: سجل كل بيع (نقد/أجل/أميال باي) — يلتقط كل المبيعات لا الرقمية فقط.
 *
 * عام لكل التصنيفات (مطعم/جملة/صيدلية...) عبر حقل category حرّ، بلا افتراضات نوع.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->string('name', 160);
            $table->decimal('price', 20, 4)->default(0);
            $table->string('category', 80)->nullable();   // حرّ: مطعم/صيدلية/جملة...
            $table->string('barcode', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_user_id', 'is_active']);
            $table->index(['merchant_user_id', 'barcode']);
        });

        Schema::create('merchant_sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_ulid', 40)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('pos_user_id')->nullable()->index();

            $table->decimal('total_amount', 20, 4);
            // طريقة الدفع: cash | credit | amial_pay
            $table->string('payment_method', 16);
            // الحالة: completed | credit_unpaid | credit_paid
            $table->string('status', 16)->default('completed')->index();

            // عناصر البيع كـ JSON (خفيف — لا جدول عناصر منفصل في الـ MVP)
            $table->json('items')->nullable();

            // للأجل: بيانات العميل المدين
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 32)->nullable();

            // لأميال باي / تسوية الأجل: ربط بعملية الدفع
            $table->string('paid_transaction_id', 40)->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            // للتقرير اليومي السريع
            $table->index(['merchant_user_id', 'created_at']);
            $table->index(['merchant_user_id', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_sales');
        Schema::dropIfExists('merchant_products');
    }
};
