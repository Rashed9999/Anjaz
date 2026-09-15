<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-WHOLESALE-STOCK-001
 *
 * مخزون الجملة لا يُخزَّن بوحدات عرض متناقضة. `current_stock` يبقى دائماً
 * بوحدة الأساس، وتحوَّل كل فاتورة إلى هذه الوحدة داخل الخادم. الدفعات هي
 * مصدر الصلاحية والكمية، لا حقل expiry وهمي على الصنف.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wholesale_product_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('code', 32);
            $table->string('name', 64);
            $table->decimal('factor_to_base', 14, 4);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'code']);
        });

        Schema::create('wholesale_product_lots', function (Blueprint $table) {
            $table->id();
            $table->string('lot_ulid', 26)->unique();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('lot_number', 80);
            $table->string('location', 120)->nullable();
            $table->date('received_at');
            $table->date('expiry_date')->nullable()->index();
            $table->decimal('quantity_received', 14, 4);
            $table->decimal('quantity_available', 14, 4);
            $table->decimal('cost_per_base_unit', 14, 4)->nullable();
            $table->string('supplier_reference', 120)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['product_id', 'lot_number', 'location'], 'wholesale_lot_location_unique');
            $table->index(['product_id', 'status', 'expiry_date']);
        });

        Schema::table('wholesale_invoice_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id')->index();
            $table->decimal('unit_factor', 14, 4)->default(1)->after('quantity');
            $table->decimal('base_quantity', 14, 4)->default(0)->after('unit_factor');
        });

        Schema::table('wholesale_return_items', function (Blueprint $table) {
            $table->decimal('unit_factor', 14, 4)->default(1)->after('quantity');
            $table->decimal('base_quantity', 14, 4)->default(0)->after('unit_factor');
        });

        Schema::create('wholesale_invoice_item_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_item_id')->index();
            $table->unsignedBigInteger('lot_id')->index();
            $table->decimal('base_quantity', 14, 4);
            $table->timestamps();

            $table->unique(['invoice_item_id', 'lot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_invoice_item_lots');
        Schema::table('wholesale_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['product_unit_id', 'unit_factor', 'base_quantity']);
        });
        Schema::table('wholesale_return_items', function (Blueprint $table) {
            $table->dropColumn(['unit_factor', 'base_quantity']);
        });
        Schema::dropIfExists('wholesale_product_lots');
        Schema::dropIfExists('wholesale_product_units');
    }
};
