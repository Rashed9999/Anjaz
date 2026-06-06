<?php

/**
 * AMIAL-2FA-001 (v1.8)
 *
 * Two-Factor Authentication (TOTP) للـ admins.
 *
 * يضيف أعمدة 2FA + جدول backup codes + recovery.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable(); // encrypted base32 secret
                $table->boolean('two_factor_enabled')->default(false);
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->text('two_factor_recovery_codes')->nullable(); // encrypted JSON
            }
        });

        // سجل محاولات 2FA (للأمان)
        if (!Schema::hasTable('two_factor_attempts')) {
            Schema::create('two_factor_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->boolean('success')->default(false);
                $table->enum('method', ['totp', 'recovery_code'])->default('totp');
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('attempted_at')->useCurrent();

                $table->index(['user_id', 'attempted_at']);
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_attempts');
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'two_factor_secret', 'two_factor_enabled',
                'two_factor_confirmed_at', 'two_factor_recovery_codes',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
