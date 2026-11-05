<?php

use App\Support\Access\AccessConstants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $deleted = DB::transaction(function (): bool {
            // This is the orphan created on 2026-08-06, not the merchant
            // acceptance price or any customer's transfer/withdrawal price.
            $scheme = DB::table('fee_schemes')->where('id', 11)->lockForUpdate()->first();
            if ($scheme === null) {
                return false;
            }

            if ($scheme->code !== 'MERCHANT_QR'
                || $scheme->zone_code !== 'SOUTH'
                || $scheme->applies_to !== 'customer'
                || $scheme->plan !== null
                || (int) $scheme->version !== 1
                || (string) $scheme->percent_rate !== '15.0000') {
                throw new RuntimeException('Customer QR scheme 11 differs from the verified unused record.');
            }

            // Production's fee_scheme_id columns were inspected before this
            // migration. A late transaction must still block deletion.
            foreach (['transactions', 'safe_payments', 'withdrawal_requests', 'bill_payment_orders'] as $table) {
                if (DB::table($table)->where('fee_scheme_id', $scheme->id)->exists()) {
                    throw new RuntimeException("Cannot delete fee scheme 11: {$table} references it.");
                }
            }

            DB::table('fee_schemes')->where('id', $scheme->id)->delete();

            // fee_change_logs is intentionally append-only. Its sole creation
            // event is historical evidence, not an operative price.
            return true;
        });

        if ($deleted) {
            Cache::forget('fee_scheme:MERCHANT_QR:SOUTH:customer:*');
            Cache::forget('fee_scheme:MERCHANT_QR:SOUTH:customer');
            foreach (AccessConstants::ALL_PLANS as $plan) {
                Cache::forget("fee_scheme:MERCHANT_QR:SOUTH:customer:{$plan}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Restoring the removed 15% price requires an explicit new decision.');
    }
};
