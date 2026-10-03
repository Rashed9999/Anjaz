<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PURCHASE-RETURN-SETTLEMENT-002
 *
 * قيمة المرتجع ليست كلها «نقداً» لمجرد أن التسوية cash_refund.
 * مثال: شراء 80 ألف، دُفع 30 وبقي 50. عند رد البضاعة كاملة:
 * 50 تُسقط الدائن، و30 فقط تعود نقداً. هذه الأعمدة تثبّت التقسيم كما وقع
 * وقت الاعتماد كي لا تعيد التقارير استنتاج التاريخ من رصيد المورد الحالي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->decimal('debt_applied', 20, 4)->default(0)
                ->after('total_amount');
            $table->decimal('credit_created', 20, 4)->default(0)
                ->after('debt_applied');
            $table->decimal('cash_refund_amount', 20, 4)->default(0)
                ->after('credit_created');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn([
                'debt_applied', 'credit_created', 'cash_refund_amount',
            ]);
        });
    }
};
