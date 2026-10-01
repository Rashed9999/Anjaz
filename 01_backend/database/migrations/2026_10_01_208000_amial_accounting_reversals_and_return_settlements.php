<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        Schema::table('suppliers', function (Blueprint $table) {
            // ما للمورد علينا يبقى current_debt، وما لنا عند المورد بعد
            // مرتجعٍ يفوق الدين يُحفظ منفصلاً بدل دفنه في ملاحظة.
            $table->decimal('current_credit', 20, 4)->default(0)
                ->after('current_debt');
        });

        Schema::table('supplier_ledger', function (Blueprint $table) {
            $table->decimal('credit_after', 20, 4)->default(0)
                ->after('debt_after');
        });

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

        // أي مصروف أُلغي قبل نشر هذه الهجرة لا يجوز أن يختفي من الماضي.
        // نولّد له reversal بتاريخ الإلغاء نفسه؛ فيبقى المصروف في يومه ويظهر
        // التصحيح في يوم الإلغاء.
        DB::table('merchant_expenses')
            ->where('status', 'voided')
            ->whereNotNull('voided_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('merchant_expense_reversals')->insertOrIgnore([
                        'reversal_ulid' => (string) Str::ulid(),
                        'merchant_user_id' => $row->merchant_user_id,
                        'expense_id' => $row->id,
                        'amount' => $row->amount,
                        'effective_on' => substr((string) $row->voided_at, 0, 10),
                        'reason' => $row->void_reason ?: 'ترحيل إلغاء تاريخي',
                        'created_by' => $row->created_by,
                        'created_at' => $row->voided_at,
                        'updated_at' => $row->voided_at,
                    ]);
                }
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
        Schema::table('supplier_ledger', function (Blueprint $table) {
            $table->dropColumn('credit_after');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('current_credit');
        });

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
