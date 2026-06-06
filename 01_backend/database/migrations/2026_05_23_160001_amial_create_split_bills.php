<?php

/**
 * AMIAL-SPLIT-BILL-001 — تقسيم الفاتورة (الوثيقة §15).
 *
 * التاجر/POS ينشئ فاتورة بمبلغ + عدد مشاركين، يُقسّم تلقائياً مع فرق التقريب،
 * كل مشارك (عميل مسجّل في v1) يستلم طلب دفع بحصته، وكل دفعة تذهب لحساب
 * التاجر الرئيسي عبر مسار دفع التاجر (الرسم من المحرّك).
 *
 * قيود: لا تكرار رقم داخل نفس الفاتورة، لا تعديل بعد أول دفع.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('split_bills', function (Blueprint $table) {
            $table->id();
            $table->string('split_ulid', 40)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('pos_user_id')->nullable()->index();
            $table->decimal('total_amount', 20, 4);
            $table->unsignedInteger('participant_count');
            $table->string('channel', 8)->default('qr'); // qr | pos
            // open | partially_paid | completed | cancelled
            $table->string('status', 20)->default('open')->index();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('split_bill_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('split_bill_id')->index();
            $table->unsignedBigInteger('customer_user_id')->index();
            $table->string('customer_phone', 32)->nullable();
            $table->decimal('share_amount', 20, 4);
            // pending | paid | cancelled
            $table->string('status', 16)->default('pending')->index();
            $table->string('paid_transaction_id', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            // لا تكرار رقم/عميل داخل نفس الفاتورة
            $table->unique(['split_bill_id', 'customer_user_id'], 'split_participant_unique');
        });

        // ربط العملية بالفاتورة والمشارك (الوثيقة §15: تسجل كل دفعة split_bill_id و participant_id)
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'split_bill_id')) {
                $table->unsignedBigInteger('split_bill_id')->nullable()->after('pos_user_id');
                $table->index('split_bill_id');
            }
            if (!Schema::hasColumn('transactions', 'split_participant_id')) {
                $table->unsignedBigInteger('split_participant_id')->nullable()->after('split_bill_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'split_participant_id')) {
                $table->dropColumn('split_participant_id');
            }
            if (Schema::hasColumn('transactions', 'split_bill_id')) {
                $table->dropIndex(['split_bill_id']);
                $table->dropColumn('split_bill_id');
            }
        });
        Schema::dropIfExists('split_bill_participants');
        Schema::dropIfExists('split_bills');
    }
};
