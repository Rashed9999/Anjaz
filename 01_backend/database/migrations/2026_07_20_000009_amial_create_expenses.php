<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-EXPENSES-001 — المصروفات والصندوق النثري.
 * تسجيل مصاريف التاجر (إيجار/رواتب/كهرباء…) لتُخصم من الأرباح الحقيقية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_expenses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->string('category', 40)->default('other'); // rent/salary/utilities/supplies/other
            $t->string('title', 160);
            $t->decimal('amount', 14, 2);
            $t->date('spent_on');
            $t->string('note', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->index('merchant_user_id', 'expense_merchant_idx');
            $t->index(['merchant_user_id', 'spent_on'], 'expense_merchant_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_expenses');
    }
};
