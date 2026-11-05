<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** تاريخ الإنتاج يخص التشغيلة لا الصنف: قد تتغير التشغيلة لنفس الدواء. */
    public function up(): void
    {
        Schema::table('pharmacy_batches', function (Blueprint $table) {
            $table->date('manufactured_at')->nullable()->after('received_date');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_batches', function (Blueprint $table) {
            $table->dropColumn('manufactured_at');
        });
    }
};
