<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-MERCHANT-ACCOUNTING-REVERSAL-001
 *
 * لا نمحو أثراً مالياً بعد أن دخل وردية أو تقريراً:
 * - إلغاء المصروف يكتب reversal مستقل.
 * - رد أصل ثابت للمورد يكتب adjustment مستقل ولا يعيد كتابة تكلفة الاقتناء.
 * - استرداد المورد النقدي يستطيع تحديد درج POS بعينه.
 * - استبعاد الأصل يجمّد القيمة الدفترية والربح/الخسارة ومصدر المتحصلات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('cashier_shift_id')->nullable()
                ->after('settlement_type')->index();
        });

        Schema::create('merchant_asset_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('asset_id')->index();
            $table->unsignedBigInteger('purchase_return_id')->nullable()->index();
            $table->string('type', 32)->index(); // supplier_return
            $table->decimal('quantity', 12, 3);
            $table->decimal('cost_amount', 20, 4);
            $table->decimal('salvage_amount', 20, 4)->default(0);
            $table->decimal('depreciation_reversed', 20, 4)->default(0);
            $table->date('effective_on');
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['asset_id', 'purchase_return_id'],
                'maa_asset_purchase_return_unique'
            );
        });

        Schema::create('merchant_expense_reversals', function (Blueprint $table) {
            $table->id();
            $table->string('reversal_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('expense_id')->unique();
            $table->decimal('amount', 20, 4);
            $table->date('effective_on')->index();
            $table->string('reason', 500);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(
                ['merchant_user_id', 'effective_on'],
                'mer_owner_effective_idx'
            );
        });

        Schema::table('merchant_fixed_assets', function (Blueprint $table) {
            $table->decimal('disposal_book_value', 20, 4)->nullable()
                ->after('disposal_proceeds');
            $table->decimal('disposal_gain_loss', 20, 4)->nullable()
                ->after('disposal_book_value');
            $table->string('disposal_payment_source', 24)->nullable()
                ->after('disposal_gain_loss');
            $table->unsignedBigInteger('disposal_cashier_shift_id')->nullable()
                ->after('disposal_payment_source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_fixed_assets', function (Blueprint $table) {
            $table->dropIndex(['disposal_cashier_shift_id']);
            $table->dropColumn([
                'disposal_book_value', 'disposal_gain_loss',
                'disposal_payment_source', 'disposal_cashier_shift_id',
            ]);
        });

        Schema::dropIfExists('merchant_expense_reversals');
        Schema::dropIfExists('merchant_asset_adjustments');

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropIndex(['cashier_shift_id']);
            $table->dropColumn('cashier_shift_id');
        });
    }
};
