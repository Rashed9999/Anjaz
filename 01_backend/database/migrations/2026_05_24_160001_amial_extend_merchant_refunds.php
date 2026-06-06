<?php

/**
 * AMIAL-CASHIER-REFUND-001 — توسيع merchant_refunds لدعم بيوع الكاشير الحديثة.
 *
 * المشكلة في الجدول الأصلي (2026_05_19_100002):
 *   - customer_user_id إجباري → يقصر المرتجعات على عملاء أميال باي المسجّلين فقط.
 *   - مربوط بـ original_transaction_id (جدول transactions القديم) فقط.
 *   - يدعم استرداد للمحفظة فقط — لا نقد ولا حساب ديون.
 *
 * بيوع الكاشير الحديثة:
 *   - 60% نقدي (بلا عميل مسجّل).
 *   - 30% أجل (باسم/هاتف فقط).
 *   - 10% أميال باي.
 *
 * هذا الـ migration يوسّع الجدول لدعم الثلاثة.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_refunds', function (Blueprint $table) {
            // اجعل customer_user_id nullable (السابق إجباري)
            $table->unsignedBigInteger('customer_user_id')->nullable()->change();

            // الربط بـ merchant_sales الجديد (في الكاشير)
            $table->string('original_sale_ulid', 26)->nullable()->after('original_transaction_id')->index();

            // نسخ من العملية الأصلية (للمرتجعات بلا حساب مسجّل)
            $table->string('customer_phone', 32)->nullable()->after('customer_user_id');
            $table->string('customer_name', 120)->nullable()->after('customer_phone');

            // طريقة الاسترداد:
            //   cash           = نقد للعميل (بلا حركة مالية رقمية)
            //   wallet         = ائتمان محفظة (يلزم customer_user_id)
            //   credit_account = خصم من دَيْن العميل (يلزم credit_account_id)
            $table->string('refund_method', 16)->default('wallet')->after('refund_amount');

            // ربط بحساب الديون إن كانت طريقة الاسترداد credit_account
            $table->unsignedBigInteger('credit_account_id')->nullable()->after('refund_method');

            // الأصناف المُرتجعة بتفاصيلها (للسجل والتحقّق من عدم التجاوز)
            $table->json('items')->nullable()->after('credit_account_id');
        });

        // ضع الـ enum القديم في عمود string ليقبل قيماً جديدة
        // (mysql لا يسمح بإضافة قيم للـ enum مباشرةً — نحوّله لـ string)
        try {
            DB::statement("ALTER TABLE merchant_refunds MODIFY COLUMN status VARCHAR(32) NOT NULL DEFAULT 'completed'");
        } catch (\Throwable $e) {
            // sqlite/postgres: قد لا يحتاج
        }
    }

    public function down(): void
    {
        Schema::table('merchant_refunds', function (Blueprint $table) {
            $table->dropColumn(['original_sale_ulid', 'customer_phone', 'customer_name',
                'refund_method', 'credit_account_id', 'items']);
        });
    }
};
