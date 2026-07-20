<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-LOYALTY-001 — برنامج الولاء والنقاط.
 *   loyalty_programs   — إعداد التاجر (معدّل الكسب/الاستبدال).
 *   loyalty_accounts   — رصيد نقاط كل عميل لدى التاجر (بمفتاح الهاتف).
 *   loyalty_movements  — دفتر حركات النقاط (كسب/استبدال/تعديل).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id')->unique();
            $t->boolean('is_active')->default(true);
            // نقاط تُكتسب لكل 100 ر.ي (مثال: 1 نقطة / 100 ر.ي)
            $t->decimal('earn_points_per_100', 12, 2)->default('1');
            // قيمة النقطة بالريال عند الاستبدال (مثال: 1 نقطة = 1 ر.ي)
            $t->decimal('redeem_value_per_point', 12, 4)->default('1');
            $t->unsignedInteger('min_redeem_points')->default(0);
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
        });

        Schema::create('loyalty_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->string('customer_phone', 32);
            $t->string('customer_name')->nullable();
            $t->decimal('points_balance', 14, 2)->default('0');
            $t->decimal('total_earned', 14, 2)->default('0');
            $t->decimal('total_redeemed', 14, 2)->default('0');
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->unique(['merchant_user_id', 'customer_phone'], 'loyalty_acct_merchant_phone_uq');
            $t->index('merchant_user_id', 'loyalty_acct_merchant_idx');
        });

        Schema::create('loyalty_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('loyalty_account_id');
            $t->enum('type', ['earn', 'redeem', 'adjust'])->default('earn');
            $t->decimal('points', 14, 2); // موجب كسب/تعديل زائد، سالب استبدال
            $t->decimal('balance_after', 14, 2);
            $t->string('sale_ulid', 40)->nullable();
            $t->string('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index('loyalty_account_id', 'loyalty_mov_acct_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_movements');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_programs');
    }
};
