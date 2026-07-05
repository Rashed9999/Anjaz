<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\PharmacyProduct;
use App\Models\PosUser;
use App\Models\User;
use App\Models\WholesaleProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-BARCODE-001 — Endpoint موحّد للبحث السريع بالباركود.
 *
 * يخدم كل القطاعات (wholesale + pharmacy + retail).
 * مُحسّن للأداء: lookup مباشر بـ barcode index، لا joins، يُرجع منتج واحد فقط.
 *
 * الاستخدام في الـ continuous scanner:
 *   GET /api/v1/amial/barcode/lookup?barcode=6291100096101&context=wholesale
 */
class BarcodeLookupController extends Controller
{
    /**
     * يبحث عن منتج بالباركود في النطاق الصحيح (wholesale|pharmacy|retail).
     * يُرجع منتجاً واحداً أو null.
     */
    public function lookup(Request $request): JsonResponse
    {
        $barcode = trim((string)$request->query('barcode', ''));
        if ($barcode === '') {
            return $this->error('NO_BARCODE', 'الباركود مطلوب', 422);
        }

        $context = $request->query('context', 'auto');

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        // 'auto' يحدّد القطاع من business_type
        if ($context === 'auto') {
            $profile = MerchantProfile::where('user_id', $merchant->id)->first();
            $context = match ($profile?->business_type) {
                'wholesale' => 'wholesale',
                'pharmacy' => 'pharmacy',
                // كل قطاعات التجزئة (retail/general/restaurant/…) تستخدم كتالوج الكاشير
                default => 'retail',
            };
        }

        $product = match ($context) {
            'wholesale' => $this->lookupWholesale($merchant->id, $barcode),
            'pharmacy' => $this->lookupPharmacy($merchant->id, $barcode),
            'retail' => $this->lookupRetail($merchant, $barcode),
            default => $this->lookupRetail($merchant, $barcode),
        };

        if (!$product) {
            return new JsonResponse([
                'success' => false,
                'code' => 'NOT_FOUND',
                'message' => 'لا يوجد منتج بهذا الباركود',
                'errors' => (object)[],
                'meta' => ['barcode' => $barcode, 'context' => $context],
            ], 404);
        }

        return $this->ok([
            'product' => $product,
            'barcode' => $barcode,
            'context' => $context,
        ]);
    }

    private function lookupWholesale(int $merchantId, string $barcode): ?array
    {
        $businessId = \App\Models\WholesaleBusiness::where('merchant_user_id', $merchantId)->value('id');
        if (!$businessId) return null;

        $product = WholesaleProduct::where('business_id', $businessId)
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->first();

        return $product ? array_merge($product->toArray(), [
            '_type' => 'wholesale',
            'available_stock' => (float)$product->current_stock,
        ]) : null;
    }

    private function lookupPharmacy(int $merchantId, string $barcode): ?array
    {
        $pharmacyId = \App\Models\Pharmacy::where('merchant_user_id', $merchantId)->value('id');
        if (!$pharmacyId) return null;

        $product = PharmacyProduct::where('pharmacy_id', $pharmacyId)
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->first();

        return $product ? array_merge($product->toArray(), [
            '_type' => 'pharmacy',
            'available_stock' => (float)$product->current_stock,
        ]) : null;
    }

    /**
     * قطاع التجزئة العام (بقالة/سوبرماركت/مطعم/متجر) — كتالوج الكاشير (MerchantProduct).
     * نفس مصدر بحث الكاشير (CashierService::findByBarcode) لتوحيد النتيجة.
     */
    private function lookupRetail(User $merchant, string $barcode): ?array
    {
        $product = \App\Models\MerchantProduct::where('merchant_user_id', $merchant->id)
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->first();

        return $product ? array_merge($product->toArray(), [
            '_type' => 'retail',
            'available_stock' => $product->quantity !== null ? (float)$product->quantity : null,
        ]) : null;
    }

    // ============ Helpers ============

    private function resolveMerchant(Request $request): array|JsonResponse
    {
        $authUser = $request->user();
        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();
        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            return [$merchant, $pos->id];
        }
        if (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار فقط', 403);
        }
        return [$authUser, null];
    }

    private function ok(array $meta): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => 'OK',
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }
}
