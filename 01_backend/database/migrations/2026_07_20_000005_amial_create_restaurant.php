<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-RESTAURANT-001 — قطاع المطاعم (طاولات + طلبات + شاشة مطبخ).
 *   restaurant_tables  — طاولات المطعم وحالتها.
 *   restaurant_orders  — طلبات الطاولة/السفري بحالاتها وأصنافها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->string('label', 60);
            $t->unsignedInteger('seats')->default(4);
            $t->enum('status', ['free', 'occupied'])->default('free');
            $t->boolean('is_active')->default(true);
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->unique(['merchant_user_id', 'label'], 'rest_table_merchant_label_uq');
            $t->index('merchant_user_id', 'rest_table_merchant_idx');
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->unsignedBigInteger('table_id')->nullable(); // null = سفري/تيك أواي
            $t->string('order_no', 24);
            // open → preparing → ready → served → closed (أو cancelled)
            $t->enum('status', ['open', 'preparing', 'ready', 'served', 'closed', 'cancelled'])->default('open');
            $t->json('items')->nullable();
            $t->decimal('subtotal', 14, 2)->default('0');
            $t->decimal('total', 14, 2)->default('0');
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('opened_by')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('sale_ulid', 40)->nullable();
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->index('merchant_user_id', 'rest_order_merchant_idx');
            $t->index(['merchant_user_id', 'status'], 'rest_order_merchant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_orders');
        Schema::dropIfExists('restaurant_tables');
    }
};
