<?php

/**
 * CRITICAL-001-SUBS — سجل التدقيق لتغييرات الاشتراكات.
 *
 * كل تغيير على subscription_plan أو subscription_expires_at
 * يجب أن يُسجَّل هنا. هذا يعطي:
 *   - audit كامل: من غيّر ماذا ومتى
 *   - تاريخ ترقيات/تخفيضات كل تاجر
 *   - أساس لحساب churn rate و MRR التاريخي
 *
 * الـ table تحوي:
 *   - subject: التاجر المتأثّر
 *   - actor: من نفّذ التغيير (admin أو system)
 *   - action: نوع التغيير
 *   - حالة قبل + بعد (snapshot كامل)
 *   - notes اختيارية
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->id();

            // التاجر المتأثّر
            $table->unsignedBigInteger('merchant_user_id');
            $table->index('merchant_user_id', 'sc_merchant');

            // من نفّذ التغيير (null = النظام / Cron Job)
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_role', 32)->nullable(); // 'admin', 'system'

            // نوع التغيير
            $table->string('action', 24);
            // 'upgrade', 'downgrade', 'renew', 'extend',
            // 'change_plan' (تغيير عام), 'cancel', 'expire_auto', 'manual'

            // Snapshot قبل التغيير
            $table->string('old_plan', 32)->nullable();
            $table->timestamp('old_expires_at')->nullable();

            // Snapshot بعد التغيير
            $table->string('new_plan', 32);
            $table->timestamp('new_expires_at')->nullable();

            // معلومات إضافية
            $table->decimal('price_paid_sar', 10, 2)->nullable(); // المبلغ المدفوع (إن وُجد)
            $table->string('payment_method', 24)->nullable(); // 'cash', 'bank', 'amial_pay'
            $table->string('payment_reference', 64)->nullable(); // رقم الإيصال
            $table->text('notes')->nullable();

            // metadata للـ analytics
            $table->json('metadata')->nullable(); // أي بيانات إضافية

            $table->timestamps();

            // فهارس مساعِدة
            $table->index(['merchant_user_id', 'created_at'], 'sc_merchant_time');
            $table->index('action', 'sc_action');
            $table->index('created_at', 'sc_time');
            $table->index('new_expires_at', 'sc_expires'); // للاستعلامات عن "المنتهية قريباً"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
    }
};
