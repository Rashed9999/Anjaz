<?php

/**
 * AMIAL-FUEL-001 — قطاع محطات الوقود.
 *
 * البنية (7 جداول):
 *   - fuel_stations: المحطّة (واحدة لكل تاجر).
 *   - fuel_pumps: المضخّات (يدوية أو إلكترونية).
 *   - fuel_products: أنواع الوقود (بنزين، ديزل) مع السعر اللحظي.
 *   - fuel_pump_products: ربط المضخّة بأنواع الوقود التي تخدمها.
 *   - fuel_sales: عمليات البيع (باللتر أو بالمبلغ).
 *   - fuel_meter_readings: سجل قراءات العدّاد (للتدقيق).
 *   - fuel_company_accounts: حسابات الشركات للدفع الآجل.
 *
 * النوبات وبطاقات الشركات في migrations منفصلة لاحقاً.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ============ المحطّة ============
        Schema::create('fuel_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->unique(); // محطة واحدة لكل تاجر
            $table->string('station_name', 120);
            $table->string('license_number', 64)->nullable();   // رقم رخصة المحطة
            $table->string('city', 80)->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();
        });

        // ============ المضخّات ============
        Schema::create('fuel_pumps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('station_id')->index();
            $table->integer('pump_number');             // رقم المضخّة (1، 2، 3...)
            $table->string('pump_name', 80)->nullable(); // وصف اختياري ("المضخّة الجنوبية")

            // نوع المضخّة:
            //   mechanical = يدوية، الموظف يقرأ العدّاد قبل/بعد البيع
            //   electronic = إلكترونية، البيع يدخل لحظياً (مستقبلاً عبر integration)
            $table->string('pump_type', 16)->default('mechanical');

            // العدّاد الحالي (للميكانيكية فقط — للتدقيق)
            $table->decimal('current_meter_reading', 14, 3)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['station_id', 'pump_number']);
        });

        // ============ أنواع الوقود ============
        Schema::create('fuel_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('station_id')->index();
            $table->string('name', 80);                          // "بنزين 91"، "ديزل"، ...
            $table->string('product_code', 32)->nullable();      // P91، DSL، ...
            $table->decimal('price_per_liter', 12, 4);           // السعر الحالي
            $table->string('color_hex', 7)->nullable();          // لون مميّز في الواجهة
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['station_id', 'is_active']);
        });

        // ============ ربط المضخّة بأنواع الوقود ============
        Schema::create('fuel_pump_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pump_id')->index();
            $table->unsignedBigInteger('fuel_product_id')->index();
            $table->integer('nozzle_number')->default(1); // فوّهة المضخّة (1، 2...)
            $table->timestamps();

            $table->unique(['pump_id', 'fuel_product_id'], 'pump_product_unique');
        });

        // ============ عمليات البيع ============
        Schema::create('fuel_sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('pos_user_id')->nullable()->index();
            $table->unsignedBigInteger('station_id')->index();
            $table->unsignedBigInteger('pump_id')->index();
            $table->unsignedBigInteger('fuel_product_id')->index();

            // طريقة البيع:
            //   by_liters = أدخل اللترات → احسب المبلغ
            //   by_amount = أدخل المبلغ → احسب اللترات
            $table->string('sale_type', 16);

            $table->decimal('liters', 12, 4);              // الكمية المباعة
            $table->decimal('price_per_liter', 12, 4);     // snapshot للسعر وقت البيع
            $table->decimal('total_amount', 14, 4);

            // طريقة الدفع:
            //   cash = نقدي
            //   amial_pay = QR أو رقم حساب أميال باي
            //   company_card = بطاقة شركة (حساب آجل)
            $table->string('payment_method', 16);

            // مرجع المعاملة (إن amial_pay)
            $table->string('paid_transaction_id', 64)->nullable();
            // مرجع حساب الشركة (إن company_card)
            $table->unsignedBigInteger('company_account_id')->nullable()->index();
            $table->string('company_card_id', 40)->nullable(); // رقم البطاقة المستخدمة

            // معلومات السيارة (اختيارية، مفيدة لكشف الحساب الشركاتي)
            $table->string('vehicle_plate', 32)->nullable();
            $table->string('driver_name', 120)->nullable();

            // قراءات العدّاد (للميكانيكية فقط)
            $table->decimal('meter_reading_before', 14, 3)->nullable();
            $table->decimal('meter_reading_after', 14, 3)->nullable();

            // الحالة: completed | refunded | voided
            $table->string('status', 16)->default('completed');

            $table->text('notes')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['merchant_user_id', 'created_at']);
            $table->index(['station_id', 'created_at']);
        });

        // ============ سجل قراءات العدّاد (للتدقيق) ============
        Schema::create('fuel_meter_readings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pump_id')->index();
            $table->decimal('reading', 14, 3);
            $table->string('reading_type', 16)->default('manual'); // manual | auto | shift_open | shift_close
            $table->unsignedBigInteger('taken_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index(['pump_id', 'taken_at']);
        });

        // ============ حسابات الشركات (دفع آجل) ============
        Schema::create('fuel_company_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->index(); // المحطة
            $table->string('company_name', 200);
            $table->string('contact_person', 120)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('tax_number', 64)->nullable();

            $table->decimal('credit_limit', 14, 4)->default(0);     // حد ائتمان كلي
            $table->decimal('current_balance', 14, 4)->default(0);  // المدين الحالي
            $table->decimal('monthly_limit', 14, 4)->default(0);    // الحد الشهري (0 = بلا حد)

            $table->timestamp('last_payment_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['merchant_user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_company_accounts');
        Schema::dropIfExists('fuel_meter_readings');
        Schema::dropIfExists('fuel_sales');
        Schema::dropIfExists('fuel_pump_products');
        Schema::dropIfExists('fuel_products');
        Schema::dropIfExists('fuel_pumps');
        Schema::dropIfExists('fuel_stations');
    }
};
