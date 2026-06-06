<?php

/**
 * CRITICAL-001 — Foundation للأدوار + الخطط + Business Types + Features.
 *
 * يضيف:
 *   users.role                        (user | agent | distributor | merchant | admin)
 *   users.verification_level          (basic | verified | premium)
 *   merchant_profiles.business_type   (nullable — يختاره التاجر لاحقاً)
 *   merchant_profiles.subscription_plan        (افتراضياً 'free')
 *   merchant_profiles.subscription_expires_at  (للإشارة فقط — لا تجميد تلقائي في v1)
 *   merchant_profiles.subscription_notes
 *   merchant_profiles.extra_features  (JSON — features إضافية يُفعّلها الأدمن يدوياً)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // role: مفصول عن type القديم (1=admin, 2=user, 3=merchant, 4=agent) للتوافق.
            // نُحدّثه من type في الـ seeder/migration.
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role', 24)->default('user')->after('type')->index();
            } else {
                // MERGE 6cash: العمود موجود كـ tinyint في القاعدة — حوّله إلى string
                $table->string('role', 24)->default('user')->change();
            }
            if (!Schema::hasColumn('users', 'verification_level')) {
                $table->string('verification_level', 16)->default('basic')->after('role');
            }
        });

        Schema::table('merchant_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_profiles', 'business_type')) {
                $table->string('business_type', 32)->nullable()->after('verification_status')->index();
            }
            if (!Schema::hasColumn('merchant_profiles', 'subscription_plan')) {
                $table->string('subscription_plan', 24)->default('free')->after('business_type')->index();
            }
            if (!Schema::hasColumn('merchant_profiles', 'subscription_expires_at')) {
                $table->timestamp('subscription_expires_at')->nullable()->after('subscription_plan');
            }
            if (!Schema::hasColumn('merchant_profiles', 'subscription_notes')) {
                $table->text('subscription_notes')->nullable();
            }
            if (!Schema::hasColumn('merchant_profiles', 'extra_features')) {
                $table->json('extra_features')->nullable();
            }
        });

        // ترقية role من النوع القديم (type)
        // type: 1=admin, 2=user, 3=merchant, 4=agent
        \DB::statement("UPDATE users SET role = CASE
            WHEN type = 1 THEN 'admin'
            WHEN type = 3 THEN 'merchant'
            WHEN type = 4 THEN 'agent'
            ELSE 'user'
        END WHERE role = 'user' OR role IS NULL");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'verification_level')) $table->dropColumn('verification_level');
            if (Schema::hasColumn('users', 'role')) $table->dropColumn('role');
        });
        Schema::table('merchant_profiles', function (Blueprint $table) {
            foreach (['business_type', 'subscription_plan', 'subscription_expires_at',
                     'subscription_notes', 'extra_features'] as $col) {
                if (Schema::hasColumn('merchant_profiles', $col)) $table->dropColumn($col);
            }
        });
    }
};
