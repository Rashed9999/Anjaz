<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder — نقطة الدخول الافتراضية لـ `php artisan db:seed`.
 *
 * كان هذا الملف مفقوداً (انظر FIXES.md) ممّا يجعل الأمر الافتراضي يفشل.
 *
 * يشغّل البذور الأساسية الآمنة للتكرار (idempotent) فقط:
 *   - نظاما الصلاحيات (المركزي + POS)
 *   - بيانات مرجعية افتراضية (رسوم، حدود KYC، قواعد AML، فئات التبرّع، مزوّدو الفواتير)
 *
 * البذور التالية لا تُشغّل تلقائياً (شغّلها يدوياً عند الحاجة):
 *   - LoadTestSeeder            (بيانات اختبار حمل فقط)
 *   - MerchantProfileBackfillSeeder (backfill لبيانات قائمة)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // RBAC — المركزي (rbac_* tables) + POS (roles/permissions/pos_user_roles)
            RbacDefaultSeeder::class,
            RbacSeeder::class,

            // بيانات مرجعية افتراضية
            FeeSchemeSeeder::class,
            KycTierLimitsSeeder::class,
            AmlDefaultRulesSeeder::class,
            CharityCategoriesSeeder::class,
            BillProvidersStubSeeder::class,
        ]);
    }
}
