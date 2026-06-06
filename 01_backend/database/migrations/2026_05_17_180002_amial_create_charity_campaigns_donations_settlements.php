<?php

/**
 * AMIAL-DONATIONS-001 (v1.2)
 *
 * الحملات + التبرعات + التسويات.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== الحملات ======
        Schema::create('charity_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_ulid', 26)->unique();
            $table->unsignedBigInteger('org_id');
            $table->unsignedBigInteger('category_id');

            // محتوى
            $table->string('title_ar', 200);
            $table->text('description_ar');
            $table->text('story_md')->nullable(); // قصة كاملة بـ markdown
            $table->string('location_ar', 200)->nullable(); // المحافظة/المنطقة

            // المالي
            $table->decimal('target_amount', 20, 4);
            $table->decimal('current_amount', 20, 4)->default(0);
            $table->decimal('platform_fee_collected', 20, 4)->default(0);

            // المستفيدون
            $table->unsignedInteger('beneficiary_count')->nullable(); // كم شخص
            $table->string('beneficiary_description_ar', 500)->nullable();

            // وسائط
            $table->string('cover_image_url', 500)->nullable();
            $table->json('gallery_images')->nullable(); // array of URLs

            // التواريخ
            $table->timestamp('start_at')->useCurrent();
            $table->timestamp('deadline_at')->nullable();

            // الحالة
            $table->enum('status', [
                'draft',              // org/admin يحضرها
                'pending_approval',   // أُرسلت للموافقة
                'active',             // معتمدة، تستقبل تبرعات
                'paused',             // أوقفت مؤقتاً (admin or org)
                'completed',          // وصلت للهدف أو لـ deadline
                'cancelled',          // ألغيت
            ])->default('draft')->index();

            $table->unsignedBigInteger('approved_by_admin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();

            // إحصاءات
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('donor_count')->default(0);
            $table->boolean('is_featured')->default(false);

            // Zone
            $table->string('zone_code', 16)->default('SOUTH');

            $table->timestamps();

            $table->index(['status', 'deadline_at']);
            $table->index(['org_id', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['is_featured', 'status']);

            $table->foreign('org_id')->references('id')->on('charity_organizations')->onDelete('restrict');
            $table->foreign('category_id')->references('id')->on('charity_categories')->onDelete('restrict');
            $table->foreign('approved_by_admin_id')->references('id')->on('users')->onDelete('set null');
        });

        // ====== التبرعات الفردية ======
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->string('donation_ulid', 26)->unique();

            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('org_id'); // denormalized
            $table->unsignedBigInteger('donor_user_id');

            // الخصوصية: لو is_anonymous فالاسم لا يظهر في public stats
            // لكن الـ donor_user_id مُسجَّل دائماً للـ audit
            $table->boolean('is_anonymous')->default(false);

            // المبلغ
            $table->decimal('amount', 20, 4); // المبلغ الكامل المخصوم من المتبرع
            $table->decimal('platform_fee', 20, 4)->default(0);
            $table->decimal('net_to_charity', 20, 4); // amount - fee

            // الـ wallet transaction المرجعية
            $table->string('wallet_transaction_id', 32);

            // الـ receipt
            $table->unsignedBigInteger('receipt_id')->nullable();

            // رسالة من المتبرع (اختياري)
            $table->string('donor_message', 500)->nullable();

            // الحالة
            $table->enum('status', [
                'completed',   // تم الخصم وتم تسجيل التبرع (default)
                'refunded',    // أُلغي من admin (نادر، يحدث في حالات خاصة)
                'settled',     // تمت تسويته للمنظمة
            ])->default('completed')->index();

            $table->unsignedBigInteger('settlement_id')->nullable(); // عند التسوية
            $table->timestamp('donated_at')->useCurrent();
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_reason')->nullable();

            // Zone
            $table->string('zone_code', 16)->default('SOUTH');

            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['donor_user_id', 'donated_at']);
            $table->index(['org_id', 'status']);
            $table->index(['settlement_id']);

            $table->foreign('campaign_id')->references('id')->on('charity_campaigns')->onDelete('restrict');
            $table->foreign('org_id')->references('id')->on('charity_organizations')->onDelete('restrict');
            $table->foreign('donor_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('receipt_id')->references('id')->on('receipts')->onDelete('set null');
        });

        // ====== التسويات الشهرية ======
        Schema::create('charity_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_ulid', 26)->unique();
            $table->unsignedBigInteger('org_id');

            // الفترة
            $table->date('period_start');
            $table->date('period_end');

            // الإحصاءات
            $table->unsignedInteger('donation_count')->default(0);
            $table->unsignedInteger('campaign_count')->default(0);
            $table->decimal('total_donations', 20, 4)->default(0);
            $table->decimal('total_platform_fees', 20, 4)->default(0);
            $table->decimal('payable_amount', 20, 4)->default(0); // total - fees

            // الحالة
            $table->enum('status', [
                'pending',      // تم إنشاؤها، لم تُحوَّل بعد
                'transferred',  // تم التحويل البنكي
                'failed',       // فشل التحويل
                'cancelled',    // أُلغيت
            ])->default('pending')->index();

            // التنفيذ
            $table->timestamp('transferred_at')->nullable();
            $table->string('bank_transfer_reference', 100)->nullable();
            $table->text('transfer_notes')->nullable();

            // التقرير PDF
            $table->string('report_pdf_path', 500)->nullable();

            // الإدارة
            $table->unsignedBigInteger('generated_by_admin_id');
            $table->unsignedBigInteger('transferred_by_admin_id')->nullable();

            $table->timestamps();

            $table->unique(['org_id', 'period_start', 'period_end'], 'org_period_unique');
            $table->index(['status', 'created_at']);

            $table->foreign('org_id')->references('id')->on('charity_organizations')->onDelete('restrict');
            $table->foreign('generated_by_admin_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('transferred_by_admin_id')->references('id')->on('users')->onDelete('set null');
        });

        // Foreign key للـ donations -> settlement (بعد إنشاء الجدول)
        Schema::table('donations', function (Blueprint $table) {
            $table->foreign('settlement_id')->references('id')->on('charity_settlements')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropForeign(['settlement_id']);
        });
        Schema::dropIfExists('charity_settlements');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('charity_campaigns');
    }
};
