<?php

/**
 * AMIAL-TRANSFER-COOLDOWN-001 (v2.7)
 *
 * نافذة إلغاء التحويل — حل قوي لمشكلة "التحويل بالغلط".
 *
 * **المبدأ (الخيار الآمن):**
 *   عند التحويل، المال يُخصم من المرسل فوراً (محجوز في حساب معلّق)،
 *   لكن لا يصل للمستلم إلا بعد انتهاء نافذة الإلغاء (افتراضي 60 ثانية).
 *   خلال النافذة، يمكن للمرسل الإلغاء واسترداد ماله بالكامل.
 *
 * **لماذا هذا أأمن من "إرسال فوري + إلغاء"؟**
 *   لو وصل المال فوراً، قد يسحبه المستلم قبل الإلغاء → استحالة الاسترداد.
 *   هنا المال محجوز، لا أحد يلمسه حتى انتهاء النافذة.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_ulid', 26)->unique();

            $table->unsignedBigInteger('sender_user_id');
            $table->unsignedBigInteger('recipient_user_id');

            $table->decimal('amount', 20, 4);
            $table->decimal('fee', 20, 4)->default('0');
            $table->decimal('total_debited', 20, 4); // amount + fee (محجوز من المرسل)
            $table->string('note', 500)->nullable();

            // الحالة
            $table->enum('status', [
                'holding',      // محجوز، ضمن نافذة الإلغاء
                'completed',    // وصل للمستلم
                'cancelled',    // ألغاه المرسل، استُرد المال
                'failed',       // فشل التسليم، استُرد المال
            ])->default('holding')->index();

            // نافذة الإلغاء
            $table->timestamp('releasable_at'); // متى ينتهي الحجز ويُسلَّم
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 300)->nullable();

            // الربط بالمعاملات الفعلية
            $table->string('hold_transaction_id', 64)->nullable(); // خصم المرسل
            $table->string('release_transaction_id', 64)->nullable(); // تسليم المستلم
            $table->string('idempotency_key', 100)->nullable();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['status', 'releasable_at']); // للـ job الذي يُسلّم
            $table->index(['sender_user_id', 'created_at']);
            $table->index(['recipient_user_id', 'status']);

            $table->foreign('sender_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('recipient_user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_transfers');
    }
};
