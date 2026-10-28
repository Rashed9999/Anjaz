<?php

namespace App\Console\Commands;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\Pharmacy;
use App\Models\PharmacyProduct;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleProduct;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Read-only extraction snapshot for a single approved merchant owner.
 * A deliberately private CSV; never export a wallet, debt, client or identity record.
 */
final class ExportMerchantCatalog extends Command
{
    protected $signature = 'merchant:export-legacy-catalog {owner : Amial Pay merchant owner user ID}';
    protected $description = 'Export product/barcode catalog for offline Amial Merchant import (no writes to legacy DB)';

    public function handle(): int
    {
        $ownerId = (string) $this->argument('owner');
        if (! ctype_digit($ownerId) || ! User::query()->whereKey((int) $ownerId)->exists()) {
            $this->error('A valid existing merchant owner ID is required.');
            return self::FAILURE;
        }
        $profile = MerchantProfile::query()->where('user_id', (int) $ownerId)->first();
        $sector = (string) ($profile?->business_type ?? '');
        if (! in_array($sector, ['retail', 'quick_sale', 'restaurant', 'pharmacy', 'wholesale'], true)) {
            $this->error('This exporter supports retail, quick_sale, restaurant, pharmacy and wholesale only. Fuel must use its separate engine.');
            return self::FAILURE;
        }
        $folder = storage_path('app/private');
        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            $this->error('Cannot create private export directory.');
            return self::FAILURE;
        }
        $file = $folder.'/merchant-catalog-'.$ownerId.'-'.now()->format('YmdHis').'.csv';
        $fp = fopen($file, 'xb');
        if (! $fp) {
            $this->error('Could not create private export file.');
            return self::FAILURE;
        }
        chmod($file, 0600);
        $rows = 0;
        $products = 0;
        $barcodes = 0;
        try {
            fputcsv($fp, ['source','legacy_id','sku','name','price','active','barcode','pack_quantity','is_primary']);
            $emit = function (string $source, int $id, ?string $sku, string $name, string $price,
                              bool $active, string $barcode, int $pack, bool $primary)
                        use ($fp, &$rows, &$barcodes): void {
                $safeSku = trim((string) $sku);
                if ($safeSku === '') {
                    $safeSku = 'legacy-'.$source.'-'.$id;
                }
                if ($barcode !== '' && ! preg_match('/^[A-Za-z0-9_.-]{3,64}$/D', $barcode)) {
                    throw new RuntimeException('Nonportable barcode for legacy product '.$id);
                }
                if ($pack < 1 || $pack > 10000) {
                    throw new RuntimeException('Noninteger or out-of-range pack for legacy product '.$id);
                }
                if (fputcsv($fp, [$source, $id, $safeSku, $name, $price,
                    $active ? '1' : '0', $barcode, (string) $pack, $primary ? '1' : '0']) === false) {
                    throw new RuntimeException('Failed to write export row.');
                }
                $rows++;
                if ($barcode !== '') {
                    $barcodes++;
                }
            };

            if (in_array($sector, ['retail','quick_sale','restaurant'], true)) {
                MerchantProduct::query()->where('merchant_user_id', (int) $ownerId)
                    ->with('barcodes')->orderBy('id')->chunkById(200, function ($items) use ($sector, $emit, &$products): void {
                        foreach ($items as $item) {
                            // The new catalog has no variant hierarchy yet. Do not silently flatten it.
                            if ($item->is_variant_parent || (is_array($item->variant_attributes) && $item->variant_attributes !== [])) {
                                throw new RuntimeException('Variant product hierarchy is not supported: '.$item->id);
                            }
                            $products++;
                            $aliases = [];
                            foreach ($item->barcodes as $linked) {
                                $code = trim((string) $linked->barcode);
                                if ($code === '') continue;
                                $quantity = (string) $linked->pack_size;
                                if (! preg_match('/^[0-9]+(?:\.0+)?$/D', $quantity)) {
                                    throw new RuntimeException('Fractional barcode pack size needs separate stock migration: '.$item->id);
                                }
                                $pack = (int) $quantity;
                                $aliases[$code] = ['pack' => $pack, 'primary' => (bool) $linked->is_primary];
                            }
                            $mirror = trim((string) $item->barcode);
                            if ($mirror !== '' && ! isset($aliases[$mirror])) {
                                $hasPrimary = collect($aliases)->contains(fn ($b) => $b['primary']);
                                $aliases[$mirror] = ['pack' => 1, 'primary' => ! $hasPrimary];
                            }
                            if ($aliases === []) {
                                $emit($sector, (int)$item->id, $item->sku, (string)$item->name,
                                    (string)$item->price, (bool)$item->is_active, '', 1, false);
                            } else {
                                foreach ($aliases as $code => $meta) {
                                    $emit($sector, (int)$item->id, $item->sku, (string)$item->name,
                                        (string)$item->price, (bool)$item->is_active,
                                        $code, $meta['pack'], $meta['primary']);
                                }
                            }
                        }
                    });
            } elseif ($sector === 'wholesale') {
                $businesses = WholesaleBusiness::query()->where('merchant_user_id', (int) $ownerId)->pluck('id');
                if ($businesses->count() !== 1) throw new RuntimeException('Merchant must have exactly one wholesale business for this export.');
                WholesaleProduct::query()->where('business_id', $businesses[0])->orderBy('id')
                    ->chunkById(200, function ($items) use ($emit, &$products): void {
                        foreach ($items as $item) {
                            $products++;
                            $barcode = trim((string) $item->barcode);
                            $emit('wholesale', (int)$item->id, $item->sku, (string)$item->name,
                                (string)$item->base_price, (bool)$item->is_active,
                                $barcode, 1, $barcode !== '');
                        }
                    });
            } else {
                $pharmacies = Pharmacy::query()->where('merchant_user_id', (int) $ownerId)->pluck('id');
                if ($pharmacies->count() !== 1) throw new RuntimeException('Merchant must have exactly one pharmacy for this export.');
                PharmacyProduct::query()->where('pharmacy_id', $pharmacies[0])->orderBy('id')
                    ->chunkById(200, function ($items) use ($emit, &$products): void {
                        foreach ($items as $item) {
                            $products++;
                            $barcode = trim((string) $item->barcode);
                            $emit('pharmacy', (int)$item->id, $item->sku, (string)$item->trade_name,
                                (string)$item->sale_price, (bool)$item->is_active,
                                $barcode, 1, $barcode !== '');
                        }
                    });
            }
            if ($products === 0) throw new RuntimeException('No source products found. Refusing an empty migration artifact.');
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($file);
            $this->error('EXPORT_ABORTED: '.$e->getMessage());
            return self::FAILURE;
        }
        fclose($fp);
        $this->line(json_encode([
            'file' => $file, 'sha256' => hash_file('sha256', $file),
            'sector' => $sector, 'products' => $products,
            'barcodes' => $barcodes, 'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->warn('Commercial catalog/barcodes ONLY. Stock, branches, pharmacy batches, variants, tiers, debts, POS and payment history were NOT exported.');
        return self::SUCCESS;
    }
}
