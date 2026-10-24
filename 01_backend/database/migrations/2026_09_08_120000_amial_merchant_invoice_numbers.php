<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أرقام الفواتير الجديدة تخص الفواتير التي تصدر بعد هذه الهجرة فقط.
 * لا نعيد تسمية فواتير أو مراجع تاريخية؛ رقم البيع الداخلي يبقى قابلاً
 * للمراجعة، والرقم المطبوع الجديد يُحفظ صراحةً بجواره.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_user_id');
            $table->string('series', 4);
            $table->unsignedSmallInteger('calendar_year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['merchant_user_id', 'series', 'calendar_year'], 'merchant_invoice_series_year_uq');
        });

        foreach (['merchant_sales', 'fuel_sales', 'pharmacy_sales', 'restaurant_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('invoice_number', 32)->nullable()->after('sale_ulid');
            });
        }

        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->unique(['merchant_user_id', 'invoice_number'], 'merchant_sale_invoice_uq');
        });
        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->unique(['merchant_user_id', 'invoice_number'], 'fuel_sale_invoice_uq');
        });
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->unique(['merchant_user_id', 'invoice_number'], 'pharmacy_sale_invoice_uq');
        });
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->unique(['merchant_user_id', 'invoice_number'], 'restaurant_invoice_uq');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropUnique('restaurant_invoice_uq');
            $table->dropColumn('invoice_number');
        });
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->dropUnique('pharmacy_sale_invoice_uq');
            $table->dropColumn('invoice_number');
        });
        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->dropUnique('fuel_sale_invoice_uq');
            $table->dropColumn('invoice_number');
        });
        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->dropUnique('merchant_sale_invoice_uq');
            $table->dropColumn('invoice_number');
        });
        Schema::dropIfExists('merchant_invoice_sequences');
    }
};
