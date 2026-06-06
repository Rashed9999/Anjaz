<?php

/**
 * AMIAL-MERCHANT-VERIFY-001 — توثيق التاجر (§13).
 *
 * المستندات المطلوبة (حسب وثيقة المتطلبات §13):
 *   - الهوية الشخصية للمالك
 *   - إثبات النشاط (سجل تجاري)
 *   - صورة المحل
 *   - إثبات العنوان
 *   - بيانات البنك (اختياري)
 *   - وثيقة اختيارية
 *
 * الحالات: unverified → pending_review → verified | rejected | resubmission_required | verification_suspended
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_verification_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();

            // اسم النشاط ورقم السجل التجاري
            $table->string('business_name', 255);
            $table->string('commercial_register_number', 64)->nullable();
            $table->string('business_category', 80)->nullable();   // نوع النشاط
            $table->string('city', 80)->nullable();
            $table->text('address')->nullable();

            // مسارات المستندات (نخزّن المسار النسبي في storage)
            $table->string('id_card_front_path', 500)->nullable();
            $table->string('id_card_back_path', 500)->nullable();
            $table->string('commercial_register_path', 500)->nullable();
            $table->string('store_photo_path', 500)->nullable();
            $table->string('address_proof_path', 500)->nullable();
            $table->string('profession_license_path', 500)->nullable();
            $table->string('optional_document_path', 500)->nullable();

            // بيانات البنك (اختياري للتسويات)
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('bank_account_holder', 120)->nullable();

            $table->string('contact_phone', 32)->nullable();    // رقم مسؤول النشاط

            // الحالة + سبب الرفض/إعادة الرفع
            $table->string('status', 32)->default('pending_review')->index();
            // pending_review | verified | rejected | resubmission_required | verification_suspended
            $table->text('admin_note')->nullable();              // تعليق الإدارة
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['merchant_user_id', 'status']);
            $table->index('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_verification_requests');
    }
};
