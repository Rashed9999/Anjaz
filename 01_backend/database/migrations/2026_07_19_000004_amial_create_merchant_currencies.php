<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MULTI-CURRENCY-001 — عملات التاجر وأسعار صرفها مقابل العملة الأساس (ر.ي).
 * rate_to_base = كم ر.ي تساوي وحدة واحدة من هذه العملة (مثل: 1 USD = 530 ر.ي).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('merchant_currencies')) {
            Schema::create('merchant_currencies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_user_id')->index();
                $table->string('code', 8);            // USD, SAR, EUR…
                $table->string('name', 40);
                $table->string('symbol', 8)->nullable();
                $table->decimal('rate_to_base', 18, 6)->default(1); // 1 وحدة = كم ر.ي
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['merchant_user_id', 'code'], 'merchant_currency_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_currencies');
    }
};
