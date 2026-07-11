<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-001 — حقول توثيق إضافية: التوقيع الإلكتروني + الإقرار بصحة المعلومات.
 * (العنوان `address` موجود أصلاً على users.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'kyc_signature')) {
                $table->string('kyc_signature', 255)->nullable();
            }
            if (!Schema::hasColumn('users', 'kyc_declaration_accepted')) {
                $table->boolean('kyc_declaration_accepted')->default(false);
            }
            if (!Schema::hasColumn('users', 'kyc_declared_at')) {
                $table->timestamp('kyc_declared_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            foreach (['kyc_signature', 'kyc_declaration_accepted', 'kyc_declared_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
