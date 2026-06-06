<?php

/**
 * AMIAL-CASHIER-001 (v2) — توسعة المنتجات:
 *   - quantity: المخزون (يدعم كسور للوزن/الكيلو).
 *   - production_date / expiry_date: مهمّان للصيدليات والأغذية.
 *   - cost_price: للتكلفة وحساب الهامش.
 *   - offer_price: سعر العرض (إن وُجد يُستخدم بدل price عند البيع).
 *   (price الموجود = سعر البيع الأساسي.)
 *
 * + حالة بيع pending_payment لربط أميال باي التلقائي بالبيع.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_products', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_products', 'cost_price')) {
                $table->decimal('cost_price', 20, 4)->default(0)->after('price');
            }
            if (!Schema::hasColumn('merchant_products', 'offer_price')) {
                $table->decimal('offer_price', 20, 4)->nullable()->after('cost_price');
            }
            if (!Schema::hasColumn('merchant_products', 'quantity')) {
                $table->decimal('quantity', 20, 3)->default(0)->after('offer_price');
            }
            if (!Schema::hasColumn('merchant_products', 'production_date')) {
                $table->date('production_date')->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('merchant_products', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('production_date');
                $table->index(['merchant_user_id', 'expiry_date']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_products', function (Blueprint $table) {
            foreach (['expiry_date', 'production_date', 'quantity', 'offer_price', 'cost_price'] as $col) {
                if (Schema::hasColumn('merchant_products', $col)) {
                    if ($col === 'expiry_date') {
                        $table->dropIndex(['merchant_user_id', 'expiry_date']);
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
