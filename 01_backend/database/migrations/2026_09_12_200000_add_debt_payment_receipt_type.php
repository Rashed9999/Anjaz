<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CREDIT-SETTLE-TRACE-001
 *
 * سداد الآجل يحتاج نوع سند مستقل؛ استخدام pay_merchant سيحوّل «سند سداد دين»
 * إلى فاتورة شراء ويطمس سبب الحركة. MySQL/MariaDB يخزنان receipt_type كـ ENUM،
 * لذلك نضيف debt_payment صراحةً مع الحفاظ على كل القيم التاريخية.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('receipts') || !$this->usesNativeEnum()) {
            return;
        }

        DB::statement("
            ALTER TABLE receipts MODIFY COLUMN receipt_type ENUM(
                'send_money',
                'cash_in',
                'cash_out',
                'add_money',
                'withdraw',
                'pay_merchant',
                'pos_payment',
                'qr_payment',
                'refund',
                'safe_payment_funded',
                'safe_payment_released',
                'safe_payment_refunded',
                'split_bill_payment',
                'family_fund_contribute',
                'family_fund_disburse',
                'bank_settlement',
                'fee_charge',
                'donation',
                'charity_settlement',
                'debt_payment'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('receipts') || !$this->usesNativeEnum()) {
            return;
        }

        // لا يمكن تقليص ENUM وفيه سجلات من النوع الجديد؛ التح rollback يجب
        // أن يكون صريحاً لا أن يحوّل النوع إلى قيمة فارغة بصمت.
        if (DB::table('receipts')->where('receipt_type', 'debt_payment')->exists()) {
            throw new RuntimeException(
                'Cannot remove debt_payment receipt type while debt payment receipts exist.'
            );
        }

        DB::statement("
            ALTER TABLE receipts MODIFY COLUMN receipt_type ENUM(
                'send_money',
                'cash_in',
                'cash_out',
                'add_money',
                'withdraw',
                'pay_merchant',
                'pos_payment',
                'qr_payment',
                'refund',
                'safe_payment_funded',
                'safe_payment_released',
                'safe_payment_refunded',
                'split_bill_payment',
                'family_fund_contribute',
                'family_fund_disburse',
                'bank_settlement',
                'fee_charge',
                'donation',
                'charity_settlement'
            ) NOT NULL
        ");
    }

    private function usesNativeEnum(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
