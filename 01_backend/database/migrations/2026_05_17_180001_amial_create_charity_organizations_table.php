<?php

/**
 * AMIAL-DONATIONS-001 (v1.2)
 *
 * منصة التبرع — المنظمات والتصنيفات.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== التصنيفات (طعام، طبية، تعليم، إلخ) ======
        Schema::create('charity_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique(); // food, medical, education, emergency, shelter, water
            $table->string('name_ar', 100);
            $table->string('icon', 64)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // ====== المنظمات الخيرية ======
        Schema::create('charity_organizations', function (Blueprint $table) {
            $table->id();
            $table->string('org_ulid', 26)->unique();

            // معلومات أساسية
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('license_number', 100);
            $table->string('license_document_path', 500)->nullable();
            $table->text('description_ar');

            // اتصال
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 50);
            $table->string('website_url', 500)->nullable();
            $table->string('address_ar', 500)->nullable();

            // الحساب البنكي (للتسوية)
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_number', 100)->nullable();
            $table->string('bank_account_holder', 200)->nullable();
            $table->string('bank_swift', 50)->nullable();

            // وسائط
            $table->string('logo_url', 500)->nullable();
            $table->string('cover_image_url', 500)->nullable();

            // الحالة
            $table->enum('verification_status', [
                'pending_verification',  // تم التقديم، تنتظر مراجعة
                'verified',              // معتمدة، يمكنها إنشاء حملات
                'rejected',              // مرفوضة
                'suspended',             // أوقفت مؤقتاً
            ])->default('pending_verification')->index();

            $table->unsignedBigInteger('verified_by_admin_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();

            // إحصاءات (denormalized لأداء أفضل)
            $table->decimal('total_collected', 20, 4)->default(0);
            $table->unsignedInteger('total_campaigns')->default(0);
            $table->unsignedInteger('total_donors')->default(0);

            // Zone
            $table->string('zone_code', 16)->default('SOUTH');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['verification_status', 'is_active']);
            $table->index('license_number');

            $table->foreign('verified_by_admin_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charity_organizations');
        Schema::dropIfExists('charity_categories');
    }
};
