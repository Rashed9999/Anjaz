<?php

/**
 * AMIAL-MERCHANT-RISK-001 (v2.9)
 *
 * نظام تصنيف ومراقبة مخاطر التجار.
 *
 * **المشكلة:**
 *   التاجر يستقبل أموالاً من عملاء كثر (طبيعي لعمله) — لكن هذا بالضبط ما
 *   يخفي غسيل الأموال. الحماية العامة لا تميّز "تاجر نشط" من "حساب غسيل".
 *
 * **الحل:**
 *   1. تصنيف التجار بمستويات (tier) + فئة مخاطر (risk category)
 *   2. حدود حسب التصنيف (لا حد موحد)
 *   3. ملف مخاطر متجدد لكل تاجر (نشاط معلن مقابل فعلي)
 *   4. قواعد AML خاصة بالتجار (3 أنماط غسيل)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== ملف التاجر (تصنيف + توثيق + حدود) ======
        Schema::create('merchant_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();

            // التصنيف (tier)
            $table->string('tier', 24)->default('micro'); // MERGE: string (يدعم micro/small/... و standard/premium/gold)
            // micro: بقالة/كشك | small: محل | medium: متجر | large: شركة

            // فئة المخاطر
            $table->enum('risk_category', ['low', 'standard', 'elevated', 'high'])
                ->default('standard');

            // النشاط المعلن (للمقارنة بالفعلي — كشف الشذوذ)
            $table->string('business_type', 100)->nullable(); // مطعم، صيدلية، إلكترونيات...
            $table->decimal('declared_monthly_volume', 20, 4)->nullable(); // الحجم الشهري المتوقع
            $table->integer('declared_daily_customers')->nullable(); // عدد العملاء المتوقع

            // حالة التوثيق (من الوثيقة الأصلية)
            $table->enum('verification_status', [
                'unverified', 'pending_review', 'verified',
                'rejected', 'resubmission_required', 'verification_suspended',
            ])->default('unverified')->index();

            // الحدود (حسب التصنيف)
            $table->decimal('daily_receive_limit', 20, 4)->default('500000');
            $table->decimal('single_receive_limit', 20, 4)->default('100000');
            $table->decimal('monthly_receive_limit', 20, 4)->default('5000000');

            // قيود الإخراج (مهم لمنع pass-through غسيل)
            $table->boolean('can_transfer_out')->default(false); // التاجر يحوّل لغيره؟
            $table->boolean('requires_settlement_only')->default(true); // إخراج عبر تسوية بنكية فقط

            $table->unsignedBigInteger('verified_by_admin_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['tier', 'risk_category']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // ====== ملف مخاطر التاجر المتجدد (للمراقبة) ======
        Schema::create('merchant_risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->unique();

            // نقاط المخاطرة المتراكمة (EMA)
            $table->decimal('current_risk_score', 6, 2)->default('0');
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');

            // إحصاءات متجددة (للكشف عن الشذوذ)
            $table->decimal('avg_daily_volume', 20, 4)->default('0');
            $table->decimal('peak_daily_volume', 20, 4)->default('0');
            $table->integer('avg_daily_customers')->default(0);
            $table->decimal('total_received_lifetime', 20, 4)->default('0');
            $table->decimal('total_transferred_out', 20, 4)->default('0'); // pass-through indicator

            // أعلام (flags)
            $table->integer('aml_flags_count')->default(0);
            $table->integer('volume_anomaly_count')->default(0);
            $table->timestamp('last_flagged_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['risk_level', 'current_risk_score']);
        });

        // ====== سجل أحداث مخاطر التاجر ======
        Schema::create('merchant_risk_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('event_type', 50); // volume_spike, new_customers_surge, pass_through, aml_flag
            $table->decimal('risk_contribution', 6, 2)->default('0');
            $table->string('description', 500)->nullable();
            $table->json('context')->nullable();
            $table->string('transaction_ulid', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['merchant_user_id', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_risk_events');
        Schema::dropIfExists('merchant_risk_profiles');
        Schema::dropIfExists('merchant_profiles');
    }
};
