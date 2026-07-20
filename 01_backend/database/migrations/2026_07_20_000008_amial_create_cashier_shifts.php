<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SHIFT-CLOSE-001 — ورديات الكاشير ودرج النقد (تقارير X/Z).
 * تسجّل بداية الوردية برصيد افتتاحي، وتُقفَل بجرد النقد لحساب الفرق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->unsignedBigInteger('pos_user_id')->nullable(); // الكاشير (إن كان موظفاً)
            $t->decimal('opening_float', 14, 2)->default('0');  // نقد بداية الوردية
            $t->decimal('expected_cash', 14, 2)->nullable();     // المتوقّع عند الإقفال
            $t->decimal('counted_cash', 14, 2)->nullable();      // المجرود فعلاً
            $t->decimal('variance', 14, 2)->nullable();          // الفرق (مجرود - متوقّع)
            $t->decimal('cash_sales', 14, 2)->default('0');
            $t->unsignedInteger('sales_count')->default(0);
            $t->enum('status', ['open', 'closed'])->default('open');
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('opened_by')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->index('merchant_user_id', 'shift_merchant_idx');
            $t->index(['merchant_user_id', 'status'], 'shift_merchant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
