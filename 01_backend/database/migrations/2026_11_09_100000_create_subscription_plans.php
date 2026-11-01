<?php

use App\Support\Access\AccessConstants as A;
use App\Support\Access\BusinessFeatureRegistry as R;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * AMIAL-PLAN-CATALOG-001 — كتالوج الباقات يُدار من لوحة الإدارة.
 *
 * الباقة في الكتالوج: اسم · سعر شهري · مدد بخصم لكلٍّ · ميزات أميال أعمال (مربّعات) · حدود ·
 * و«المستوى الأساسيّ» (free/business/enterprise) الذي تقرؤه قدراتُ تطبيق أميال باي في ~142 موضعاً.
 * فالباقة الجديدة لا تمسّ تلك المواضع: تُخزَّن رتبتُها في subscription_plan كما كانت، ورمزُها هنا.
 *
 * والباقات الثلاث القائمة تُبذَر «نظاميّةً» بقيمها الحاليّة نفسها — فلا يتغيّر شيءٌ لأيّ تاجر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_ar', 64);
            $table->string('name_en', 64);
            $table->string('description_ar', 255)->nullable();
            $table->string('base_tier', 16);
            $table->decimal('monthly_price', 12, 2)->default(0);
            $table->char('currency', 3)->default('SAR');
            $table->json('business_features');
            $table->json('business_limits');
            $table->json('durations');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('merchant_profiles', function (Blueprint $table) {
            $table->string('subscription_plan_code', 32)->nullable()->after('subscription_plan');
        });

        $sort = 0;
        foreach (A::ALL_PLANS as $tier) {
            $monthly = (float) (A::PLAN_PRICES_SAR[$tier] ?? 0);
            $annual = (float) (A::PLAN_PRICES_SAR_ANNUAL[$tier] ?? 0);
            $durations = [['months' => 1, 'discount_percent' => 0]];
            if ($monthly > 0 && $annual > 0) {
                $durations[] = ['months' => 12, 'discount_percent' => round((1 - $annual / ($monthly * 12)) * 100, 4)];
            }
            DB::table('subscription_plans')->insert([
                'code' => $tier, 'name_ar' => A::PLAN_LABELS[$tier] ?? $tier, 'name_en' => ucfirst($tier),
                'base_tier' => $tier, 'monthly_price' => $monthly, 'currency' => 'SAR',
                'business_features' => json_encode(R::availableOn($tier)), 'business_limits' => json_encode(R::LIMITS[$tier]),
                'durations' => json_encode($durations), 'is_active' => true, 'is_public' => true, 'is_system' => true,
                'sort_order' => $sort++, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('merchant_profiles', fn (Blueprint $t) => $t->dropColumn('subscription_plan_code'));
        Schema::dropIfExists('subscription_plans');
    }
};
