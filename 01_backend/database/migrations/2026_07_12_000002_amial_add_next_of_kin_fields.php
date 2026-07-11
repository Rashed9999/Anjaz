<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-002 — حقول «شخص قريب» (next of kin) لتسجيل العميل متعدّد الخطوات.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'kin_name')) {
                $table->string('kin_name', 150)->nullable();
            }
            if (!Schema::hasColumn('users', 'kin_phone')) {
                $table->string('kin_phone', 30)->nullable();
            }
            if (!Schema::hasColumn('users', 'kin_relation')) {
                $table->string('kin_relation', 60)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            foreach (['kin_name', 'kin_phone', 'kin_relation'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
