<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROMOTIONS-001 — العروض والخصومات والكوبونات.
 *   promotions          — قواعد الخصم (تلقائي أو كوبون بكود).
 *   merchant_sales.+     — حقلا الخصم المطبَّق على كل بيع (للتقارير).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->string('name');
            $t->enum('type', ['percent', 'fixed'])->default('percent');
            $t->decimal('value', 12, 2);               // نسبة% أو مبلغ ثابت ر.ي
            $t->string('code', 40)->nullable();        // كوبون (فارغ = خصم تلقائي)
            $t->decimal('min_order_amount', 14, 2)->default('0');
            $t->decimal('max_discount_amount', 14, 2)->nullable(); // سقف للنسبة
            $t->boolean('is_active')->default(true);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->unsignedInteger('usage_limit')->nullable(); // فارغ = بلا حد
            $t->unsignedInteger('used_count')->default(0);
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->index('merchant_user_id', 'promo_merchant_idx');
            $t->index(['merchant_user_id', 'code'], 'promo_merchant_code_idx');
        });

        Schema::table('merchant_sales', function (Blueprint $t) {
            if (!Schema::hasColumn('merchant_sales', 'discount_amount')) {
                $t->decimal('discount_amount', 14, 2)->default('0')->after('total_amount');
            }
            if (!Schema::hasColumn('merchant_sales', 'promotion_id')) {
                $t->unsignedBigInteger('promotion_id')->nullable()->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sales', function (Blueprint $t) {
            if (Schema::hasColumn('merchant_sales', 'promotion_id')) $t->dropColumn('promotion_id');
            if (Schema::hasColumn('merchant_sales', 'discount_amount')) $t->dropColumn('discount_amount');
        });
        Schema::dropIfExists('promotions');
    }
};
