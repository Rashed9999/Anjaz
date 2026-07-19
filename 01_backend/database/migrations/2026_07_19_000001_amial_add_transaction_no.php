<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-TXN-NO-001 — رقم عملية رقمي مقروء (15 خانة) مميّز بنوع العملية:
 *   عميل↔عميل  → يبدأ 120
 *   عميل→تاجر  → يبدأ 20
 *   وكيل↔عميل  → يبدأ 50
 * يظهر للمستخدم بجانب «المرجع» (ULID). مشترك بين صفوف العملية الواحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'transaction_no')) {
                $table->string('transaction_no', 20)->nullable()->after('transaction_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'transaction_no')) {
                $table->dropColumn('transaction_no');
            }
        });
    }
};
