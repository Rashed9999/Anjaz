<?php

use Illuminate\Database\Migrations\Migration;

/**
 * AMIAL-BUSINESS-AML-001 — قواعد النوعين الجديدين في المنشآت القائمة (البذور لا تُعاد
 * في الإنتاج). نوعٌ مفحوص بلا قاعدة يُحجز كلّه، فتُثبَّت القواعد مع إعلان النوع.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (\App\Models\Aml\AmlRule::query()->exists()) {
            \App\Aml\BusinessAmlRules::install();
        }
    }

    public function down(): void
    {
        \App\Models\Aml\AmlRule::whereIn('code', array_column(\App\Aml\BusinessAmlRules::definitions(), 'code'))->delete();
    }
};
