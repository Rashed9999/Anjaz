<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-MERCHANT-ASSETS-001
 *
 * يكمّل دورة الشراء بدل أن نسمّي كل بندٍ غير مخزني «مصروفاً»:
 * - بند مخزون يزيد المخزون عند الاستلام.
 * - أصل ثابت ينشئ سجل أصل ويُهلك تاريخياً بلا إعادة كتابة الماضي.
 * - بند آخر يبقى مشتريات/مورد ولا يُختلق له مخزون.
 *
 * كما يعطي مستندات الموردين هوية ثابتة لإعادة الطباعة والتحقق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedBigInteger('amial_user_id')->nullable()->after('merchant_user_id')->index();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('document_ulid', 26)->nullable()->after('id')->unique();
        });

        DB::table('purchase_orders')->whereNull('document_ulid')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('purchase_orders')->where('id', $row->id)
                        ->update(['document_ulid' => (string) Str::ulid()]);
                }
            });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('item_type', 24)->default('inventory')->after('product_id')->index();
            $table->string('asset_category', 80)->nullable()->after('item_type');
            $table->unsignedSmallInteger('useful_life_months')->nullable()->after('asset_category');
            // قيمة متبقية للوحدة عند نهاية العمر؛ تُحوّل إلى إجمالي عند الاستلام.
            $table->decimal('salvage_value', 20, 4)->default(0)->after('useful_life_months');
        });

        // البنود التاريخية غير المربوطة بمنتج كانت "بنوداً حرة"، لا مخزوناً
        // مجهولاً. تصنيفها other يمنعها من اكتساب أثر مخزني بعد هذه الهجرة.
        DB::table('purchase_order_items')
            ->whereNull('product_id')
            ->update(['item_type' => 'other']);

        Schema::table('supplier_ledger', function (Blueprint $table) {
            $table->string('entry_ulid', 26)->nullable()->after('id')->unique();
            $table->string('payment_method', 24)->nullable()->after('cash_amount');
            $table->string('transaction_id', 64)->nullable()->after('reference')->index();
            $table->unsignedBigInteger('cashier_shift_id')->nullable()->after('transaction_id')->index();
            $table->string('idempotency_key', 80)->nullable()->after('cashier_shift_id')->unique();
        });

        DB::table('supplier_ledger')->whereNull('entry_ulid')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('supplier_ledger')->where('id', $row->id)
                        ->update(['entry_ulid' => (string) Str::ulid()]);
                }
            });

        Schema::create('merchant_fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_order_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->index();

            $table->string('name', 200);
            $table->string('category', 80)->default('other')->index();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('acquisition_cost', 20, 4);
            $table->decimal('salvage_value', 20, 4)->default(0);
            $table->unsignedSmallInteger('useful_life_months');
            $table->string('depreciation_method', 24)->default('straight_line');

            $table->date('acquired_on');
            $table->date('depreciation_starts_on');
            $table->string('status', 24)->default('active')->index();
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 20, 4)->nullable();
            $table->string('disposal_reason', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['merchant_user_id', 'status', 'acquired_on'], 'mfa_owner_status_date_idx');
        });

        Schema::create('merchant_asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->string('entry_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('asset_id')->index();
            $table->string('period', 7); // YYYY-MM
            $table->decimal('amount', 20, 4);
            $table->decimal('accumulated_after', 20, 4);
            $table->decimal('book_value_after', 20, 4);
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->unique(['asset_id', 'period'], 'mad_asset_period_unique');
            $table->index(['merchant_user_id', 'period'], 'mad_owner_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_asset_depreciations');
        Schema::dropIfExists('merchant_fixed_assets');

        Schema::table('supplier_ledger', function (Blueprint $table) {
            $table->dropUnique(['entry_ulid']);
            $table->dropIndex(['transaction_id']);
            $table->dropIndex(['cashier_shift_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['entry_ulid', 'payment_method', 'transaction_id', 'cashier_shift_id', 'idempotency_key']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropIndex(['item_type']);
            $table->dropColumn(['item_type', 'asset_category', 'useful_life_months', 'salvage_value']);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['document_ulid']);
            $table->dropColumn('document_ulid');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex(['amial_user_id']);
            $table->dropColumn('amial_user_id');
        });
    }
};
