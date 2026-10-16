<?php

namespace App\Services;

use App\Models\MerchantProduct;
use App\Models\Retail\ProductBarcode;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;

/**
 * Single merchant-scoped barcode index for owner web, POS, quick sale and retail.
 * merchant_products.barcode remains a backwards-compatible mirror of the primary
 * entry; alternative and pack barcodes live in product_barcodes.
 *
 * Call ensurePrimary inside the caller's DB transaction. The unique
 * merchant_user_id + barcode index is the final concurrent-write guard.
 */
class MerchantProductBarcodeService
{
    public function ensurePrimary(User $owner, MerchantProduct $product, ?string $raw): void
    {
        if ((int) $product->merchant_user_id !== (int) $owner->id) {
            throw new DomainException('هذا المنتج لا يتبع منشأتك');
        }
        $code = trim((string) $raw);
        if (mb_strlen($code) > 64) {
            throw new DomainException('الباركود يتجاوز 64 خانة');
        }
        if ($product->is_variant_parent && $code !== '') {
            throw new DomainException('الصنف الأب لا يُباع؛ عيّن باركود لكل متغيّر');
        }
        if ($code !== '') {
            $legacyOwner = MerchantProduct::where('merchant_user_id', $owner->id)
                ->where('barcode', $code)->where('id', '!=', $product->id)->first();
            if ($legacyOwner) {
                throw new DomainException('الباركود مرتبط بالفعل بالصنف: ' . $legacyOwner->name);
            }
        }
        $primary = ProductBarcode::where('merchant_user_id', $owner->id)
            ->where('product_id', $product->id)
            ->where('is_primary', true)->lockForUpdate()->first();
        if ($code === '') {
            $primary?->delete();
            $product->barcode = null;
            $product->save();
            return;
        }
        $alias = ProductBarcode::where('merchant_user_id', $owner->id)
            ->where('barcode', $code)->lockForUpdate()->first();
        if ($alias && (int) $alias->product_id !== (int) $product->id) {
            throw new DomainException('هذا الباركود مرتبط بصنف آخر في منشأتك');
        }
        try {
            if ($primary && (!$alias || $alias->id !== $primary->id)) {
                $primary->update(['is_primary' => false]);
            }
            if ($alias) {
                if (!$alias->is_primary) $alias->update(['is_primary' => true]);
            } else {
                ProductBarcode::create([
                    'merchant_user_id' => $owner->id,
                    'product_id' => $product->id,
                    'barcode' => $code,
                    'unit_id' => $product->unit_id,
                    'pack_size' => '1',
                    'is_primary' => true,
                ]);
            }
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                throw new DomainException('الباركود مستخدم بالفعل في منشأتك', 0, $e);
            }
            throw $e;
        }
        $product->barcode = $code;
        $product->save();
    }

    /** @return array{product:MerchantProduct,pack_size:string,barcode:string}|null */
    public function find(User $owner, string $barcode): ?array
    {
        $code = trim($barcode);
        if ($code === '') return null;
        $link = ProductBarcode::where('merchant_user_id', $owner->id)
            ->where('barcode', $code)->with('product')->first();
        if ($link) {
            // Historical merchant_products rows sometimes reused the same
            // barcode before the unique product_barcodes index existed.
            // Never resolve an ambiguous code to an arbitrary stock item.
            $ambiguous = MerchantProduct::where('merchant_user_id', $owner->id)
                ->whereRaw('TRIM(barcode) = ?', [$code])
                ->where('id', '!=', $link->product_id)->exists();
            if ($ambiguous) throw new DomainException('هذا الباركود مكرر على أكثر من صنف؛ يجب تصحيحه من إدارة المنتجات');
            $p = $link->product;
            if (!$p || (int) $p->merchant_user_id !== (int) $owner->id
                || !$p->is_active || $p->is_variant_parent) return null;
            return ['product' => $p, 'pack_size' => (string) $link->pack_size, 'barcode' => $code];
        }
        // Legacy rows that predate product_barcodes stay scannable.
        $legacy = MerchantProduct::where('merchant_user_id', $owner->id)
            ->whereRaw('TRIM(barcode) = ?', [$code]);
        if ((clone $legacy)->limit(2)->count() > 1)
            throw new DomainException('هذا الباركود مكرر على أكثر من صنف؛ يجب تصحيحه من إدارة المنتجات');
        $p = $legacy->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('is_variant_parent')->orWhere('is_variant_parent', false))
            ->first();
        return $p ? ['product' => $p, 'pack_size' => '1', 'barcode' => $code] : null;
    }
}
