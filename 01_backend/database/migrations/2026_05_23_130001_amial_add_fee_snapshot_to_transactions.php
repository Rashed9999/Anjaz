<?php

/**
 * AMIAL-FEE-ENGINE-001 (wiring)
 *
 * snapshot النسخة المستخدَمة من محرّك الرسوم على كل عملية مالية،
 * فتبقى العملية التاريخية مفسَّرة حتى لو تغيّرت النسبة لاحقاً.
 * nullable — العمليات القديمة والمسارات غير المربوطة بعد تبقى null.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'fee_scheme_id')) {
                $table->unsignedBigInteger('fee_scheme_id')->nullable()->after('decision_reason');
            }
            if (!Schema::hasColumn('transactions', 'fee_scheme_version')) {
                $table->unsignedInteger('fee_scheme_version')->nullable()->after('fee_scheme_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'fee_scheme_version')) {
                $table->dropColumn('fee_scheme_version');
            }
            if (Schema::hasColumn('transactions', 'fee_scheme_id')) {
                $table->dropColumn('fee_scheme_id');
            }
        });
    }
};
