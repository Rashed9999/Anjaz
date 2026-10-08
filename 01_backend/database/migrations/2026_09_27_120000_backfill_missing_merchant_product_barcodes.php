<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Catch up products created since the original August one-time backfill.
 * Safe to re-run manually: an existing code is never reassigned to a different
 * product. Historical duplicates remain untouched and are rejected on scan
 * until the owner resolves them; nothing is deleted or silently overwritten.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('merchant_products') || !Schema::hasTable('product_barcodes')) return;

        DB::table('merchant_products')->whereNotNull('barcode')->where('barcode', '!=', '')
            ->orderBy('id')->chunkById(250, function ($products): void {
                foreach ($products as $p) {
                    $code = trim((string) $p->barcode);
                    if ($code === '') continue;
                    $clashing = DB::table('merchant_products')
                        ->where('merchant_user_id', $p->merchant_user_id)
                        ->whereRaw('TRIM(barcode) = ?', [$code])->limit(2)->count();
                    if ($clashing > 1) {
                        Log::warning('merchant barcode duplicate requires manual review', [
                            'merchant_id' => $p->merchant_user_id, 'product_id' => $p->id,
                        ]);
                        continue;
                    }
                    $existing = DB::table('product_barcodes')
                        ->where('merchant_user_id', $p->merchant_user_id)->where('barcode', $code)->first();
                    if ($existing) {
                        if ((int) $existing->product_id !== (int) $p->id)
                            Log::warning('merchant barcode index conflicts with legacy product', [
                                'merchant_id' => $p->merchant_user_id, 'product_id' => $p->id,
                                'linked_product_id' => $existing->product_id,
                            ]);
                        continue;
                    }
                    DB::table('product_barcodes')->insert([
                        'merchant_user_id' => $p->merchant_user_id,
                        'product_id' => $p->id, 'barcode' => $code,
                        'unit_id' => $p->unit_id, 'pack_size' => '1',
                        'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
    }

    // Append-only repair of a shared barcode index: deleting the backfill on
    // rollback would remove labels that the merchant may already have printed.
    public function down(): void {}
};
