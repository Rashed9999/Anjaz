<?php

/**
 * AMIAL-MERCHANT-PAY-001
 *
 * pos_user_id: يربط عملية دفع التاجر بموظف نقطة البيع الذي نفّذها.
 * المبلغ يذهب لحساب التاجر الرئيسي (الوثيقة §11)، لكن العملية تُنسب لموظف POS.
 * nullable — معظم العمليات (تحويل/سحب/QR مباشر) لا تحتوي pos_user_id.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'pos_user_id')) {
                $table->unsignedBigInteger('pos_user_id')->nullable()->after('fee_scheme_version');
                $table->index('pos_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'pos_user_id')) {
                $table->dropIndex(['pos_user_id']);
                $table->dropColumn('pos_user_id');
            }
        });
    }
};
