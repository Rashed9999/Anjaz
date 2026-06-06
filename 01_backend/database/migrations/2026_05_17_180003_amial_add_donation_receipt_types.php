<?php

/**
 * AMIAL-DONATIONS-001 (v1.2)
 *
 * إضافة donation receipt types إلى الـ receipts enum.
 *
 * MySQL/MariaDB لا يدعم إضافة enum value بسهولة عبر Schema builder،
 * لذلك نستخدم raw DB::statement.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('receipts')) {
            return;
        }

        // Append new enum values: 'donation', 'charity_settlement'
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

    public function down(): void
    {
        if (!Schema::hasTable('receipts')) {
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
                'fee_charge'
            ) NOT NULL
        ");
    }
};
