<?php

/**
 * AMIAL-PHARMACY-001 — قطاع الصيدليات.
 *
 * 8 جداول:
 *   - pharmacies: الصيدلية (واحدة لكل تاجر).
 *   - pharmacy_categories: تصنيفات (أدوية، مكمّلات، عناية...).
 *   - pharmacy_products: المنتجات (SKU + باركود + اسم تجاري/علمي + يحتاج وصفة؟).
 *   - pharmacy_batches: دفعات بـ FIFO (تاريخ صلاحية + كمية متبقّية).
 *   - pharmacy_customers: العملاء/المرضى (مع حساسيات + أمراض مزمنة).
 *   - pharmacy_sales: عمليات البيع (مع رقم وصفة اختياري).
 *   - pharmacy_sale_items: عناصر البيع (مع تتبّع batch المخصوم).
 *   - pharmacy_stock_alerts: تنبيهات (انخفاض/قرب انتهاء/منتهي).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ============ الصيدلية ============
        Schema::create('pharmacies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->unique();
            $table->string('pharmacy_name', 200);
            $table->string('license_number', 64)->nullable();
            $table->string('pharmacist_name', 120)->nullable();
            $table->string('pharmacist_license', 64)->nullable();
            $table->string('city', 80)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();
        });

        // ============ التصنيفات ============
        Schema::create('pharmacy_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pharmacy_id')->index();
            $table->string('name', 80);             // "مضادات حيوية", "مكمّلات", "عناية بالأطفال"
            $table->string('icon', 40)->nullable();
            $table->string('color_hex', 7)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ============ المنتجات (الأدوية + غيرها) ============
        Schema::create('pharmacy_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pharmacy_id')->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();

            $table->string('sku', 64)->nullable();           // كود داخلي
            $table->string('barcode', 64)->nullable()->index(); // باركود للمسح
            $table->string('trade_name', 200);                // الاسم التجاري (e.g., "Panadol")
            $table->string('generic_name', 200)->nullable();  // الاسم العلمي (e.g., "Paracetamol")
            $table->string('manufacturer', 120)->nullable();
            $table->string('unit', 32)->default('قطعة');      // قطعة، علبة، شريط

            // السعر الحالي (يحدّثه التاجر؛ تاريخ السعر يُحفظ في snapshot عند البيع)
            $table->decimal('sale_price', 14, 4);
            $table->decimal('cost_price', 14, 4)->nullable(); // للهامش

            // علامات مهمّة
            $table->boolean('requires_prescription')->default(false); // يحتاج وصفة طبيب؟
            $table->boolean('is_active')->default(true);

            // حدود تنبيه المخزون
            $table->integer('low_stock_threshold')->default(10);

            // وصف + جرعات (اختياري)
            $table->text('description')->nullable();
            $table->text('dosage_instructions')->nullable();

            // الكميّة الكلّية في المخزون (محسوبة من sum(batches.remaining))
            // نحفظها هنا للسرعة، تُحدَّث عند كل تغيير على batches
            $table->decimal('current_stock', 14, 4)->default(0);

            $table->string('image_path', 500)->nullable();
            $table->timestamps();

            $table->index(['pharmacy_id', 'is_active']);
            $table->index(['pharmacy_id', 'trade_name']);
        });

        // ============ Batches (الدفعات) - FIFO ============
        Schema::create('pharmacy_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_ulid', 26)->unique();
            $table->unsignedBigInteger('product_id')->index();

            $table->string('batch_number', 64);              // الرقم الذي على العلبة
            $table->date('expiry_date')->index();
            $table->date('received_date')->nullable();

            $table->decimal('quantity_received', 14, 4);     // الكمية الأصلية المستلمة
            $table->decimal('quantity_remaining', 14, 4);    // المتبقّي (يقلّ مع البيوع)

            $table->decimal('cost_per_unit', 14, 4)->nullable(); // سعر التكلفة للـ batch
            $table->string('supplier_name', 200)->nullable();
            $table->string('supplier_invoice', 64)->nullable();

            // active = متاح للبيع (FIFO يأخذ منه)
            // exhausted = مستنفذ (quantity_remaining = 0)
            // expired = منتهي (لا يُباع، يُحفظ للسجل)
            // recalled = مسحوب
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['product_id', 'status', 'expiry_date']);
        });

        // ============ العملاء/المرضى ============
        Schema::create('pharmacy_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pharmacy_id')->index();
            $table->string('full_name', 200);
            $table->string('phone', 32)->nullable()->index();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 8)->nullable(); // male | female
            $table->boolean('is_pregnant')->default(false);
            $table->boolean('is_breastfeeding')->default(false);

            // قائمة الحساسيات (JSON array of strings)
            // مثل: ["Penicillin", "Aspirin", "Sulfa"]
            $table->json('allergies')->nullable();

            // أمراض مزمنة (JSON array)
            // مثل: ["Diabetes", "Hypertension", "Asthma"]
            $table->json('chronic_conditions')->nullable();

            // أدوية يتناولها بانتظام (للتتبّع، اختياري)
            $table->json('regular_medications')->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'phone'], 'pharmacy_customer_phone_unique');
        });

        // ============ عمليات البيع ============
        Schema::create('pharmacy_sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('pos_user_id')->nullable();
            $table->unsignedBigInteger('pharmacy_id')->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();

            // مرجع وصفة طبية (اختياري — للأدوية التي تحتاج وصفة)
            $table->string('prescription_number', 64)->nullable();
            $table->string('prescribing_doctor', 200)->nullable();
            $table->date('prescription_date')->nullable();

            $table->decimal('subtotal', 14, 4);              // قبل الخصم
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4);          // النهائي

            $table->string('payment_method', 16);            // cash | amial_pay | credit
            $table->string('paid_transaction_id', 64)->nullable();

            // ملاحظات/تحذيرات سُجِّلت (allergic_override، إلخ)
            $table->json('warnings_acknowledged')->nullable();

            $table->string('status', 16)->default('completed'); // completed | refunded | voided
            $table->text('notes')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['pharmacy_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });

        // ============ عناصر البيع (يتتبّع الـ batch المخصوم) ============
        Schema::create('pharmacy_sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('batch_id')->index(); // الـ batch الذي خُصم منه (FIFO)

            $table->string('product_trade_name', 200);     // snapshot
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);          // snapshot للسعر
            $table->decimal('total_price', 14, 4);

            $table->boolean('required_prescription')->default(false); // snapshot
            $table->timestamps();
        });

        // ============ تنبيهات المخزون ============
        Schema::create('pharmacy_stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pharmacy_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('batch_id')->nullable();

            // low_stock | near_expiry | expired
            $table->string('alert_type', 32);
            $table->string('severity', 16); // info | warning | critical

            $table->text('message');
            $table->json('details')->nullable(); // كمية، تاريخ انتهاء، إلخ

            // active | dismissed | resolved
            $table->string('status', 16)->default('active');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['pharmacy_id', 'status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_stock_alerts');
        Schema::dropIfExists('pharmacy_sale_items');
        Schema::dropIfExists('pharmacy_sales');
        Schema::dropIfExists('pharmacy_customers');
        Schema::dropIfExists('pharmacy_batches');
        Schema::dropIfExists('pharmacy_products');
        Schema::dropIfExists('pharmacy_categories');
        Schema::dropIfExists('pharmacies');
    }
};
