<?php

/**
 * AMIAL-WHOLESALE-001 — قطاع تجارة الجملة.
 *
 * 7 جداول:
 *   wholesale_businesses: المنشأة (واحدة لكل تاجر جملة).
 *   wholesale_price_tiers: شرائح الأسعار (retail/wholesale/super_wholesale).
 *   wholesale_products: المنتجات (مع cost + multi-pricing عبر علاقة).
 *   wholesale_product_prices: سعر كل منتج لكل شريحة.
 *   wholesale_customers: العملاء (مع credit_limit + payment_terms_days).
 *   wholesale_invoices: الفواتير (header).
 *   wholesale_invoice_items: عناصر الفاتورة (snapshot كامل).
 *   wholesale_collections: التحصيلات الجزئية على الفواتير.
 *   wholesale_sales_reps: مندوبي المبيعات (مع نسبة عمولة).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ============ المنشأة ============
        Schema::create('wholesale_businesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->unique();
            $table->string('business_name', 200);
            $table->string('commercial_register', 64)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->string('city', 80)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 120)->nullable();

            // الإعدادات
            $table->decimal('default_tax_rate', 5, 2)->default(0); // 5% مثلاً
            $table->string('invoice_prefix', 16)->default('INV');  // INV-0001
            $table->integer('next_invoice_number')->default(1);
            $table->integer('default_payment_terms_days')->default(30); // الافتراضي للعملاء الجدد

            $table->boolean('is_active')->default(true);
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();
        });

        // ============ شرائح الأسعار ============
        Schema::create('wholesale_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('code', 32);                  // 'retail', 'wholesale', 'super_wholesale'
            $table->string('name', 80);                  // "تجزئة", "جملة", "جملة الجملة"
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false); // الشريحة الافتراضية للعملاء الجدد
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code']);
        });

        // ============ المنتجات ============
        Schema::create('wholesale_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();

            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable()->index();
            $table->string('name', 200);
            $table->string('manufacturer', 120)->nullable();
            $table->string('unit', 32)->default('قطعة'); // قطعة، كرتون، كيس، علبة

            // متعلّق بالمخزون
            $table->decimal('current_stock', 14, 4)->default(0);
            $table->integer('low_stock_threshold')->default(10);
            $table->decimal('cost_price', 14, 4)->nullable(); // للهامش

            // السعر الأساسي (سعر الشريحة الافتراضية — للسرعة)
            $table->decimal('base_price', 14, 4);

            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'name']);
        });

        // ============ أسعار المنتجات لكل شريحة ============
        Schema::create('wholesale_product_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('tier_id')->index();
            $table->decimal('price', 14, 4);

            // min_quantity: الحدّ الأدنى من الكمّية لاستحقاق هذا السعر
            // مثال: 1+ بـ 100 ر.ي، 10+ بـ 90 ر.ي، 100+ بـ 80 ر.ي
            $table->decimal('min_quantity', 14, 4)->default(1);

            $table->timestamps();

            $table->unique(['product_id', 'tier_id', 'min_quantity'], 'wp_unique');
        });

        // ============ العملاء ============
        Schema::create('wholesale_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('default_tier_id')->nullable()->index();

            $table->string('full_name', 200);
            $table->string('company_name', 200)->nullable();
            $table->string('phone', 32)->nullable()->index();
            $table->string('email', 120)->nullable();
            $table->string('city', 80)->nullable();
            $table->text('address')->nullable();
            $table->string('tax_number', 64)->nullable();

            // الائتمان
            $table->decimal('credit_limit', 14, 4)->default(0);  // 0 = نقد فقط
            $table->decimal('current_balance', 14, 4)->default(0); // الرصيد المُستحقّ
            $table->integer('payment_terms_days')->default(30);

            // إحصائيات
            $table->decimal('total_purchases', 14, 4)->default(0);
            $table->date('last_purchase_date')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'phone'], 'wc_phone_unique');
        });

        // ============ الفواتير ============
        Schema::create('wholesale_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_ulid', 26)->unique();
            $table->string('invoice_number', 32)->index(); // INV-2026-00001
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('sales_rep_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id'); // التاجر أو موظف POS

            $table->date('invoice_date');
            $table->date('due_date');

            // الحسابات
            $table->decimal('subtotal', 14, 4);          // قبل الخصم والضريبة
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4);      // النهائي
            $table->decimal('paid_amount', 14, 4)->default(0);    // المدفوع
            $table->decimal('balance_due', 14, 4);       // المتبقّي

            // الحالة
            // draft, issued, partial_paid, paid, overdue, voided
            $table->string('status', 24)->default('issued');
            $table->string('payment_type', 16)->default('credit'); // cash | credit

            // العمولة (snapshot)
            $table->decimal('sales_rep_commission_rate', 5, 2)->nullable();
            $table->decimal('sales_rep_commission_amount', 14, 4)->nullable();

            $table->text('notes')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->unique(['business_id', 'invoice_number']);
            $table->index(['business_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index(['business_id', 'due_date']);
        });

        // ============ عناصر الفاتورة ============
        Schema::create('wholesale_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('product_id')->nullable();

            // Snapshots (حفظ كامل لكي لا تتأثر بتغييرات لاحقة)
            $table->string('product_name', 200);
            $table->string('product_sku', 64)->nullable();
            $table->string('unit', 32);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount_per_unit', 14, 4)->default(0);
            $table->decimal('line_total', 14, 4);          // (price - discount) * qty

            $table->unsignedBigInteger('tier_id')->nullable(); // الشريحة المُطبَّقة
            $table->timestamps();
        });

        // ============ التحصيلات ============
        Schema::create('wholesale_collections', function (Blueprint $table) {
            $table->id();
            $table->string('collection_ulid', 26)->unique();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('received_by_user_id');

            $table->date('collection_date');
            $table->decimal('amount', 14, 4);
            $table->string('payment_method', 16); // cash | bank_transfer | amial_pay | check
            $table->string('reference_number', 64)->nullable(); // رقم الشيك/التحويل
            $table->string('paid_transaction_id', 64)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ============ مندوبي المبيعات ============
        Schema::create('wholesale_sales_reps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('user_id')->nullable(); // إن مرتبط بحساب أميال
            $table->string('full_name', 200);
            $table->string('phone', 32)->nullable();
            $table->decimal('default_commission_rate', 5, 2)->default(0); // 5% مثلاً

            // إحصائيات
            $table->decimal('total_sales', 14, 4)->default(0);
            $table->decimal('total_commission_earned', 14, 4)->default(0);
            $table->decimal('total_commission_paid', 14, 4)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_sales_reps');
        Schema::dropIfExists('wholesale_collections');
        Schema::dropIfExists('wholesale_invoice_items');
        Schema::dropIfExists('wholesale_invoices');
        Schema::dropIfExists('wholesale_customers');
        Schema::dropIfExists('wholesale_product_prices');
        Schema::dropIfExists('wholesale_products');
        Schema::dropIfExists('wholesale_price_tiers');
        Schema::dropIfExists('wholesale_businesses');
    }
};
