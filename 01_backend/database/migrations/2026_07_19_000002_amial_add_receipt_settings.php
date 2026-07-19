<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-RECEIPT-SETTINGS-001 — إعدادات فاتورة/طباعة لكل تاجر (JSON):
 *   الشعار، الترويسة، التذييل، الهاتف، عرض الورق (58/80مم)، إظهار عناصر…
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_profiles', 'receipt_settings')) {
                $table->json('receipt_settings')->nullable()->after('extra_features');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_profiles', 'receipt_settings')) {
                $table->dropColumn('receipt_settings');
            }
        });
    }
};
