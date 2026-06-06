<?php

/**
 * AMIAL-FEE-ENGINE-001 (wiring)
 *
 * snapshot نسخة محرّك الرسوم المستخدَمة عند الإفراج للبائع.
 * nullable — السجلات القديمة والإفراج عبر fallback (config) تبقى null.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('safe_payments', 'fee_scheme_id')) {
                $table->unsignedBigInteger('fee_scheme_id')->nullable()->after('platform_fee');
            }
            if (!Schema::hasColumn('safe_payments', 'fee_scheme_version')) {
                $table->unsignedInteger('fee_scheme_version')->nullable()->after('fee_scheme_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('safe_payments', function (Blueprint $table) {
            if (Schema::hasColumn('safe_payments', 'fee_scheme_version')) {
                $table->dropColumn('fee_scheme_version');
            }
            if (Schema::hasColumn('safe_payments', 'fee_scheme_id')) {
                $table->dropColumn('fee_scheme_id');
            }
        });
    }
};
