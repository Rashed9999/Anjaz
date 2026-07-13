<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-SUPPLIERS-001 — الموردون وأوامر الشراء (تصاميم التاجر 53/57/67/68).
 *
 *  suppliers            : بيانات المورد + مديونية التاجر الحالية له
 *  supplier_ledger      : حركات المورد (استلام بضاعة يزيد الدين / سداد ينقصه)
 *  purchase_orders      : أوامر الشراء (مسودة → معتمد → مستلم جزئياً → مكتمل)
 *  purchase_order_items : بنود الأمر (المطلوب/المستلم/تكلفة الوحدة)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->string('name', 200);
            $table->string('contact_person', 200)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('category', 100)->nullable();
            // مديونية التاجر للمورد (تكبر بالاستلام، تصغر بالسداد)
            $table->decimal('current_debt', 20, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supplier_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->unsignedBigInteger('merchant_user_id')->index();
            // po_receive: استلام بضاعة (+دين) / payment: سداد (−دين) /
            // opening: رصيد افتتاحي / adjustment: تسوية يدوية
            $table->string('entry_type', 24);
            $table->decimal('amount', 20, 4);
            $table->decimal('debt_after', 20, 4);
            $table->string('reference', 64)->nullable(); // مثل PO-2026-0001
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 32)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();
            // draft → approved → partially_received → completed | cancelled
            $table->string('status', 24)->default('draft')->index();
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id')->index();
            $table->unsignedBigInteger('product_id')->nullable(); // MerchantProduct
            $table->string('name', 200);
            $table->decimal('quantity', 12, 3);
            $table->decimal('received_quantity', 12, 3)->default(0);
            $table->decimal('unit_cost', 20, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('supplier_ledger');
        Schema::dropIfExists('suppliers');
    }
};
