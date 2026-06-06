<?php

/**
 * AMIAL-FUEL-002 — بطاقات الشركات + النوبات + العجز والفائض.
 *
 * 4 جداول جديدة:
 *   - fuel_company_cards: بطاقات فردية للشركات مع حدود يومية/شهرية.
 *   - fuel_shifts: النوبات (فتح/إغلاق).
 *   - fuel_shift_pump_summaries: ملخّص لكل مضخّة في النوبة.
 *   - fuel_variance_records: سجل العجز والفائض (variance).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ============ بطاقات الشركات ============
        Schema::create('fuel_company_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_account_id')->index();
            $table->string('card_number', 40);             // رقم البطاقة (يحدّده التاجر)
            $table->string('card_label', 120)->nullable(); // وصف اختياري
            $table->string('vehicle_plate', 32)->nullable();
            $table->string('driver_name', 120)->nullable();
            $table->string('driver_phone', 32)->nullable();
            $table->decimal('daily_limit', 14, 4)->default(0);   // 0 = بلا حد
            $table->decimal('monthly_limit', 14, 4)->default(0); // 0 = بلا حد
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_account_id', 'card_number'], 'card_unique_per_company');
            $table->index(['company_account_id', 'is_active']);
        });

        // ============ النوبات ============
        Schema::create('fuel_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_ulid', 26)->unique();
            $table->unsignedBigInteger('station_id')->index();
            $table->unsignedBigInteger('opened_by_user_id');
            $table->unsignedBigInteger('closed_by_user_id')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            // اللحظة الافتتاحية: النقد في الصندوق
            $table->decimal('opening_cash', 14, 4)->default(0);

            // عند الإغلاق:
            // - expected_cash: محسوب من مبيعات cash + opening_cash
            // - actual_cash: ما عدّه الموظف فعلياً
            // - variance: الفرق (موجب = فائض، سالب = عجز)
            $table->decimal('expected_cash', 14, 4)->nullable();
            $table->decimal('actual_cash', 14, 4)->nullable();
            $table->decimal('variance', 14, 4)->nullable();

            // مجاميع النوبة (محسوبة عند الإغلاق):
            $table->decimal('total_cash_sales', 14, 4)->default(0);
            $table->decimal('total_amial_pay_sales', 14, 4)->default(0);
            $table->decimal('total_company_sales', 14, 4)->default(0);
            $table->decimal('total_liters', 14, 4)->default(0);
            $table->integer('total_sales_count')->default(0);

            // open | closed | closed_with_variance
            $table->string('status', 32)->default('open');

            $table->text('variance_reason')->nullable();      // سبب العجز/الفائض
            $table->text('opening_notes')->nullable();
            $table->text('closing_notes')->nullable();

            // موافقة إدارية للعجز الكبير
            $table->boolean('requires_admin_review')->default(false);
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['station_id', 'status']);
            $table->index('opened_at');
        });

        // ============ ملخّص لكل مضخّة في النوبة ============
        Schema::create('fuel_shift_pump_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_id')->index();
            $table->unsignedBigInteger('pump_id')->index();

            // قراءات العدّاد (للميكانيكية)
            $table->decimal('opening_meter', 14, 3)->default(0);
            $table->decimal('closing_meter', 14, 3)->nullable();

            // المتوقّع من قراءة العدّاد (closing - opening)
            $table->decimal('expected_liters', 14, 4)->nullable();
            // المُسجَّل في عمليات البيع
            $table->decimal('recorded_liters', 14, 4)->default(0);
            // الفرق
            $table->decimal('liters_variance', 14, 4)->nullable();

            $table->decimal('total_amount', 14, 4)->default(0);
            $table->integer('sales_count')->default(0);

            $table->timestamps();

            $table->unique(['shift_id', 'pump_id']);
        });

        // ============ سجل العجز والفائض (variance records) ============
        Schema::create('fuel_variance_records', function (Blueprint $table) {
            $table->id();
            $table->string('record_ulid', 26)->unique();
            $table->unsignedBigInteger('shift_id')->index();
            $table->unsignedBigInteger('station_id')->index();
            $table->unsignedBigInteger('reported_by_user_id');

            // النوع: cash_variance (عجز/فائض نقدي) أو liters_variance (عجز/فائض في اللترات)
            $table->string('variance_type', 32);

            // إشارة الفرق: shortage (عجز) أو surplus (فائض)
            $table->string('direction', 16);

            $table->decimal('amount', 14, 4); // قيمة العجز/الفائض (قيمة موجبة دائماً)
            $table->text('reason')->nullable();
            $table->text('admin_note')->nullable();

            // pending | accepted | covered_by_employee | charged_to_petty_cash | written_off
            $table->string('resolution_status', 32)->default('pending');
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['station_id', 'resolution_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_variance_records');
        Schema::dropIfExists('fuel_shift_pump_summaries');
        Schema::dropIfExists('fuel_shifts');
        Schema::dropIfExists('fuel_company_cards');
    }
};
