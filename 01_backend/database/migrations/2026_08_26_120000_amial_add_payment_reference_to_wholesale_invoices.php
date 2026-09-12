<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** حركة أميال الواحدة لا تُسوّى بها فاتورتان. */
    public function up(): void
    {
        Schema::table('wholesale_invoices', function (Blueprint $table) {
            $table->string('paid_transaction_id', 64)->nullable()->after('payment_type');
            $table->unique('paid_transaction_id', 'wi_paid_transaction_unique');
        });
        Schema::table('wholesale_collections', function (Blueprint $table) {
            $table->unique('paid_transaction_id', 'wc_paid_transaction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_invoices', function (Blueprint $table) {
            $table->dropUnique('wi_paid_transaction_unique');
            $table->dropColumn('paid_transaction_id');
        });
        Schema::table('wholesale_collections', function (Blueprint $table) {
            $table->dropUnique('wc_paid_transaction_unique');
        });
    }
};
