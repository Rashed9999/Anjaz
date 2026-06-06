<?php

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1)
 *
 * safe_payments: العمليات الرئيسية للدفع الآمن.
 *
 * مسار مستقل عن التحويل العادي. المال يُحجز ولا يصل للبائع إلا بـ:
 *   - تأكيد المشتري، أو
 *   - قرار إدارة (في حالة نزاع)
 *
 * **لاحظ:** لا نستخدم كلمة "Escrow" في أي UI نص (سياسة الوثيقة).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_ulid', 26)->unique();

            // الأطراف
            $table->unsignedBigInteger('buyer_user_id');
            $table->unsignedBigInteger('seller_user_id');

            // التفاصيل
            $table->string('title', 200);
            $table->text('description');                       // وصف ما يُشترى
            $table->text('delivery_terms')->nullable();        // شروط التسليم
            $table->json('attachments')->nullable();           // صور المنتج المتفق عليه

            // المبلغ — snapshot لا يتغير
            $table->decimal('amount', 20, 4);
            $table->decimal('platform_fee', 20, 4)->default(0);     // رسوم المنصة (تخصم عند الإفراج)
            $table->decimal('held_amount', 20, 4);                  // المحجوز حالياً (يتناقص عند release/refund)

            // الحالة (الوثيقة Section 14)
            $table->enum('status', [
                'pending_seller_acceptance',   // المشتري دفع، ينتظر قبول البائع
                'seller_rejected',             // البائع رفض → استرداد للمشتري ✗
                'funded',                      // البائع قبل، المال محجوز
                'in_delivery',                 // البائع بدأ التسليم
                'delivered',                   // البائع أكد التسليم
                'buyer_confirmed',             // المشتري أكد الاستلام (انتقالي)
                'released_to_seller',          // المال أُفرج للبائع ✓ [terminal]
                'disputed',                    // نزاع، تنتظر الإدارة
                'refunded_to_buyer',           // استرداد كامل للمشتري ✗ [terminal]
                'partially_refunded',          // استرداد جزئي + جزء للبائع ✗ [terminal]
                'cancelled',                   // ملغي قبل التسليم → استرداد للمشتري ✗ [terminal]
                'expired',                     // انتهت صلاحية → استرداد للمشتري ✗ [terminal]
            ])->default('pending_seller_acceptance')->index();

            // Money movement tracking — ULIDs للـ wallet transactions
            $table->string('buyer_debit_tx_id', 32)->nullable();     // عند funding
            $table->string('seller_credit_tx_id', 32)->nullable();   // عند release
            $table->string('buyer_refund_tx_id', 32)->nullable();    // عند refund

            // للـ partial refund
            $table->decimal('refunded_to_buyer_amount', 20, 4)->default(0);
            $table->decimal('released_to_seller_amount', 20, 4)->default(0);

            // Lifecycle timestamps
            $table->timestamp('seller_response_deadline')->nullable(); // 72h من الإنشاء
            $table->timestamp('seller_accepted_at')->nullable();
            $table->timestamp('seller_rejected_at')->nullable();
            $table->timestamp('in_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('buyer_confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            // Dispute
            $table->boolean('is_disputed')->default(false);
            $table->timestamp('disputed_at')->nullable();
            $table->unsignedBigInteger('admin_resolved_by')->nullable();
            $table->timestamp('admin_resolved_at')->nullable();
            $table->text('admin_resolution_note')->nullable();

            // Audit
            $table->string('zone_code', 16)->default('SOUTH');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['buyer_user_id', 'status']);
            $table->index(['seller_user_id', 'status']);
            $table->index(['status', 'seller_response_deadline']);
            $table->index('is_disputed');

            $table->foreign('buyer_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('seller_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('admin_resolved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_payments');
    }
};
