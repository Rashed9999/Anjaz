<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BILL-PAY-RECEIPT-001
 *
 * دفع الفواتير عملية مالية مستقلة وليست "رسوم خدمة". نضيف bill_payment
 * إلى نوع الإيصال مع الحفاظ على كل الأنواع التاريخية، بما فيها debt_payment.
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
                'debt_payment',
                'bill_payment'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        if (!Schema::hasTable('receipts') || !$this->usesNativeEnum()) {
            return;
        }

        if (DB::table('receipts')->where('receipt_type', 'bill_payment')->exists()) {
            throw new RuntimeException(
                'Cannot remove bill_payment receipt type while bill payment receipts exist.'
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
                'charity_settlement',
                'debt_payment'
            ) NOT NULL
        ");
    }

    private function usesNativeEnum(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
