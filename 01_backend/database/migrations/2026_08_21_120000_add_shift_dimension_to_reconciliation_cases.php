<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A teller drawer is a separate physical-cash custody dimension from a branch safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_cases', function (Blueprint $table) {
            if (!Schema::hasColumn('reconciliation_cases', 'shift_id')) {
                $table->unsignedBigInteger('shift_id')->nullable()->after('till_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_cases', function (Blueprint $table) {
            if (Schema::hasColumn('reconciliation_cases', 'shift_id')) {
                $table->dropColumn('shift_id');
            }
        });
    }
};
