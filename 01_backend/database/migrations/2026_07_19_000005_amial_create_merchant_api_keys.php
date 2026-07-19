<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-API-ACCESS-001 — مفاتيح API للتاجر (الباقة المؤسسية).
 * يُخزَّن هاش المفتاح فقط؛ يُعرض المفتاح الكامل مرّة واحدة عند التوليد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('merchant_api_keys')) {
            Schema::create('merchant_api_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_user_id')->index();
                $table->string('label', 60)->nullable();
                $table->string('prefix', 12);            // amk_xxxxxx (لعرضه)
                $table->string('key_hash', 64)->unique(); // sha256 للمفتاح الكامل
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_api_keys');
    }
};
