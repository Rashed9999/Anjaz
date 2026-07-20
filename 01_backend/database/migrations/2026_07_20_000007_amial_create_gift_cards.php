<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-GIFT-CARDS-001 — بطاقات الهدايا ورصيد المتجر.
 *   gift_cards              — بطاقة برصيد مخزَّن (بكود فريد لدى التاجر).
 *   gift_card_transactions  — دفتر حركات البطاقة (إصدار/استبدال/شحن/إلغاء).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('merchant_user_id');
            $t->string('code', 40);
            $t->decimal('initial_balance', 14, 2);
            $t->decimal('balance', 14, 2);
            $t->enum('status', ['active', 'depleted', 'void'])->default('active');
            $t->string('issued_to_phone', 32)->nullable();
            $t->string('issued_to_name', 120)->nullable();
            $t->date('expires_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('zone_code', 16)->default('SOUTH');
            $t->timestamps();
            $t->unique(['merchant_user_id', 'code'], 'gift_merchant_code_uq');
            $t->index('merchant_user_id', 'gift_merchant_idx');
            $t->index('issued_to_phone', 'gift_phone_idx');
        });

        Schema::create('gift_card_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('gift_card_id');
            $t->enum('type', ['issue', 'redeem', 'topup', 'void']);
            $t->decimal('amount', 14, 2);         // موجب شحن/إصدار، سالب استبدال
            $t->decimal('balance_after', 14, 2);
            $t->string('sale_ulid', 40)->nullable();
            $t->string('note', 160)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index('gift_card_id', 'gift_txn_card_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
    }
};
