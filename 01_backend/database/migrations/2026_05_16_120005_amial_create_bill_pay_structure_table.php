<?php

/**
 * AMIAL-BILL-PAY-001 (v0.9-C)
 *
 * هياكل بيانات Bill Pay:
 *   - bill_providers: شركات الاتصالات/الخدمات
 *   - bill_services: الخدمات المعروضة (شحن، إنترنت، فاتورة ثابت)
 *   - bill_service_products: المنتجات تحت كل خدمة (باقات، فئات)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->string('display_name_ar', 100);

            // ---- Provider integration ----
            $table->string('integration_type', 32)->default('stub'); // stub | http | sms
            $table->string('endpoint_url', 500)->nullable();
            $table->string('api_key_encrypted', 500)->nullable(); // مُشفَّر، يُفك في runtime
            $table->json('config')->nullable(); // إعدادات إضافية

            // ---- Status ----
            $table->boolean('is_active')->default(false);
            $table->string('zone_code', 16)->default('SOUTH');

            $table->timestamps();

            $table->index(['zone_code', 'is_active']);
        });

        Schema::create('bill_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_id');
            $table->string('code', 32);
            $table->string('name', 100);
            $table->string('display_name_ar', 100);
            $table->string('service_type', 32); // recharge | postpaid_bill | internet
            $table->string('icon_url', 500)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('requires_account_number')->default(true);
            $table->json('account_validation_rules')->nullable(); // regex، طول، إلخ
            $table->timestamps();

            $table->unique(['provider_id', 'code']);
            $table->index(['provider_id', 'is_active']);
            $table->foreign('provider_id')->references('id')->on('bill_providers')->onDelete('cascade');
        });

        Schema::create('bill_service_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->string('product_code', 64);
            $table->string('name', 200);

            // فئة ثابتة أو متغيرة
            $table->enum('amount_type', ['fixed', 'variable'])->default('fixed');
            $table->decimal('fixed_amount', 20, 4)->nullable();
            $table->decimal('min_amount', 20, 4)->nullable();
            $table->decimal('max_amount', 20, 4)->nullable();

            $table->decimal('fee_amount', 20, 4)->default(0);
            $table->decimal('fee_percent', 5, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['service_id', 'product_code']);
            $table->foreign('service_id')->references('id')->on('bill_services')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_service_products');
        Schema::dropIfExists('bill_services');
        Schema::dropIfExists('bill_providers');
    }
};
