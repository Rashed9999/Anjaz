<?php

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * إضافة encrypted columns للـ users + KYC + recovery requests.
 *
 * **المهم:**
 *   - لا نحذف plaintext columns في v1.3 (للـ rollback safety)
 *   - بعد التأكد من نجاح التشفير، نحذفها في v1.4
 *   - الـ blind_index columns مفهرسة للسرعة
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== Users ======
        Schema::table('users', function (Blueprint $table) {
            // Phone
            if (!Schema::hasColumn('users', 'phone_encrypted')) {
                $table->text('phone_encrypted')->nullable()->after('phone');
                $table->char('phone_blind_index', 64)->nullable()->after('phone_encrypted');
                $table->string('phone_masked', 30)->nullable()->after('phone_blind_index');
                $table->index('phone_blind_index', 'idx_users_phone_blind');
            }

            // Email
            if (!Schema::hasColumn('users', 'email_encrypted')) {
                $table->text('email_encrypted')->nullable();
                $table->char('email_blind_index', 64)->nullable();
                $table->string('email_masked', 100)->nullable();
                $table->index('email_blind_index', 'idx_users_email_blind');
            }

            // Names
            if (!Schema::hasColumn('users', 'f_name_encrypted')) {
                $table->text('f_name_encrypted')->nullable();
                $table->text('l_name_encrypted')->nullable();
            }

            // National ID (KYC)
            if (!Schema::hasColumn('users', 'national_id_encrypted')) {
                $table->text('national_id_encrypted')->nullable();
                $table->char('national_id_blind_index', 64)->nullable();
                $table->string('national_id_masked', 30)->nullable();
                $table->index('national_id_blind_index', 'idx_users_nid_blind');
            }

            // Address + DOB
            if (!Schema::hasColumn('users', 'address_encrypted')) {
                $table->text('address_encrypted')->nullable();
            }
            if (!Schema::hasColumn('users', 'dob_encrypted')) {
                $table->text('dob_encrypted')->nullable();
            }

            // Encryption migration tracking
            if (!Schema::hasColumn('users', 'pii_migrated_at')) {
                $table->timestamp('pii_migrated_at')->nullable()->index();
            }
        });

        // ====== Account Recovery Requests (تحتوي phones حساسة) ======
        if (Schema::hasTable('account_recovery_requests')) {
            Schema::table('account_recovery_requests', function (Blueprint $table) {
                if (!Schema::hasColumn('account_recovery_requests', 'old_phone_encrypted')) {
                    $table->text('old_phone_encrypted')->nullable();
                    $table->char('old_phone_blind_index', 64)->nullable();
                    $table->text('new_phone_encrypted')->nullable();
                    $table->char('new_phone_blind_index', 64)->nullable();
                    $table->index('old_phone_blind_index', 'idx_recovery_old_phone_blind');
                    $table->index('new_phone_blind_index', 'idx_recovery_new_phone_blind');
                }
            });
        }

        // ====== KYC files tracking (احتفاظ بـ encrypted path) ======
        // الصور الفعلية تُشفر في storage، الـ paths تبقى عادية
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'identity_image_encrypted_path')) {
                    $table->string('identity_image_encrypted_path', 500)->nullable();
                    $table->boolean('kyc_files_encrypted')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = [
                'phone_encrypted', 'phone_blind_index', 'phone_masked',
                'email_encrypted', 'email_blind_index', 'email_masked',
                'f_name_encrypted', 'l_name_encrypted',
                'national_id_encrypted', 'national_id_blind_index', 'national_id_masked',
                'address_encrypted', 'dob_encrypted',
                'pii_migrated_at',
                'identity_image_encrypted_path', 'kyc_files_encrypted',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('users', $col)) {
                    try {
                        $table->dropIndex(['phone_blind_index']);
                        $table->dropIndex(['email_blind_index']);
                        $table->dropIndex(['national_id_blind_index']);
                    } catch (\Throwable $e) { /* index may not exist */ }
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasTable('account_recovery_requests')) {
            Schema::table('account_recovery_requests', function (Blueprint $table) {
                foreach ([
                    'old_phone_encrypted', 'old_phone_blind_index',
                    'new_phone_encrypted', 'new_phone_blind_index',
                ] as $col) {
                    if (Schema::hasColumn('account_recovery_requests', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
