<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-003 — تاريخا إصدار وانتهاء وثيقة الهوية (لمعالج التسجيل).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'identification_issue_date')) {
                $table->date('identification_issue_date')->nullable();
            }
            if (!Schema::hasColumn('users', 'identification_expiry_date')) {
                $table->date('identification_expiry_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            foreach (['identification_issue_date', 'identification_expiry_date'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
