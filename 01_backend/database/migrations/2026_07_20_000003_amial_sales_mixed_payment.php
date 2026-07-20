<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MIXED-PAYMENT-001 — الدفع المختلط (جزء نقد + جزء محفظة).
 * حقلان يوضّحان تقسيم التحصيل على الفاتورة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sales', function (Blueprint $t) {
            if (!Schema::hasColumn('merchant_sales', 'cash_amount')) {
                $t->decimal('cash_amount', 14, 2)->nullable()->after('discount_amount');
            }
            if (!Schema::hasColumn('merchant_sales', 'wallet_amount')) {
                $t->decimal('wallet_amount', 14, 2)->nullable()->after('cash_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sales', function (Blueprint $t) {
            if (Schema::hasColumn('merchant_sales', 'wallet_amount')) $t->dropColumn('wallet_amount');
            if (Schema::hasColumn('merchant_sales', 'cash_amount')) $t->dropColumn('cash_amount');
        });
    }
};
