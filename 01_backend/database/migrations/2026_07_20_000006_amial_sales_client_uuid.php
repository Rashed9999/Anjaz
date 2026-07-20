<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-OFFLINE-POS-001 — مفتاح idempotency للبيع.
 * يمنع تكرار المبيعات المُخزّنة دون اتصال عند إعادة المزامنة (نفس client_uuid
 * = نفس البيع). الفهرس الفريد على (التاجر، المفتاح) و NULL مسموح للمبيعات
 * المتّصلة العادية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sales', function (Blueprint $t) {
            if (!Schema::hasColumn('merchant_sales', 'client_uuid')) {
                $t->string('client_uuid', 64)->nullable()->after('sale_ulid');
                $t->unique(['merchant_user_id', 'client_uuid'], 'sale_merchant_client_uuid_uq');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sales', function (Blueprint $t) {
            if (Schema::hasColumn('merchant_sales', 'client_uuid')) {
                $t->dropUnique('sale_merchant_client_uuid_uq');
                $t->dropColumn('client_uuid');
            }
        });
    }
};
