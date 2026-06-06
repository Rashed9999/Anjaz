<?php

/**
 * AMIAL-SCALE-FEES-001 — دفتر رسوم append-only (تحمّل عالٍ).
 *
 * المشكلة: creditAdminCharge كانت تقفل صف محفظة الأدمن الوحيد (lockForUpdate)،
 * فكل العمليات ذات الرسوم تتنافس على قفل واحد → تسلسل خانق تحت الحمل.
 *
 * الحل: كل رسم يُدرَج كصفّ مستقل هنا (الإدراج لا يتنافس على صف ساخن)، وتُسوّى
 * في رصيد الأدمن دورياً عبر أمر مجدول (amial:reconcile-fees) بقفل واحد فقط.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->index();
            $table->decimal('amount', 20, 4);
            $table->string('source_type', 32)->nullable();   // send_money, merchant_qr, cash_out...
            $table->string('transaction_id', 40)->nullable()->index();
            $table->unsignedBigInteger('from_user_id')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->boolean('reconciled')->default(false)->index();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            // للتسوية السريعة
            $table->index(['reconciled', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_entries');
    }
};
