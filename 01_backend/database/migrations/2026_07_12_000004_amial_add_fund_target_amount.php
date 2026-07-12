<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-FUND-002 — المبلغ المستهدف للصندوق العائلي (هدف الادّخار).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('family_funds')) {
            return;
        }
        Schema::table('family_funds', function (Blueprint $table) {
            if (!Schema::hasColumn('family_funds', 'target_amount')) {
                $table->decimal('target_amount', 20, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('family_funds')) {
            return;
        }
        Schema::table('family_funds', function (Blueprint $table) {
            if (Schema::hasColumn('family_funds', 'target_amount')) {
                $table->dropColumn('target_amount');
            }
        });
    }
};
