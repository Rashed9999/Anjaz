<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_limits', function (Blueprint $table) {
            $table->decimal('todays_amount', 20, 4)->default(0)->change();
            $table->decimal('this_months_amount', 20, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        foreach (['todays_amount', 'this_months_amount'] as $column) {
            if (DB::table('transaction_limits')->whereRaw(
                "ABS($column) > 99999999.99 OR $column <> ROUND($column, 2)"
            )->exists()) {
                throw new RuntimeException('Existing counters exceed the previous decimal precision.');
            }
        }
        Schema::table('transaction_limits', function (Blueprint $table) {
            $table->decimal('todays_amount', 10, 2)->default(0)->change();
            $table->decimal('this_months_amount', 10, 2)->default(0)->change();
        });
    }
};
