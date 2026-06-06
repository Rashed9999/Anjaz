<?php

namespace App\Services;

use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyProduct;
use App\Models\PharmacyStockAlert;

/**
 * AMIAL-PHARMACY-001 — خدمة تنبيهات المخزون.
 *
 * أنواع التنبيهات:
 *   - low_stock: كمية المنتج أقل من الحد.
 *   - near_expiry: batch قريب من الانتهاء (30 يوم افتراضياً).
 *   - expired: batch منتهي (يُحجب تلقائياً من البيع).
 */
class PharmacyAlertService
{
    private const NEAR_EXPIRY_DAYS = 30;

    /** يفحص منتجاً ويُنشئ تنبيه low_stock إن لزم. */
    public function checkLowStock(PharmacyProduct $product): ?PharmacyStockAlert
    {
        if (!$product->isLowStock()) {
            // أعد فتح تنبيه قديم إن كان موجوداً
            PharmacyStockAlert::where('product_id', $product->id)
                ->where('alert_type', 'low_stock')
                ->where('status', 'active')
                ->update(['status' => 'resolved']);
            return null;
        }

        // ابحث عن تنبيه نشط حالياً (نتجنّب التكرار)
        $existing = PharmacyStockAlert::where('product_id', $product->id)
            ->where('alert_type', 'low_stock')
            ->where('status', 'active')
            ->first();
        if ($existing) return $existing;

        $isCritical = (float)$product->current_stock <= 0;
        return PharmacyStockAlert::create([
            'pharmacy_id' => $product->pharmacy_id,
            'product_id' => $product->id,
            'alert_type' => 'low_stock',
            'severity' => $isCritical ? 'critical' : 'warning',
            'message' => $isCritical
                ? "نفد المخزون من {$product->trade_name}"
                : "مخزون منخفض من {$product->trade_name} (المتبقّي: {$product->current_stock})",
            'details' => [
                'current_stock' => (string)$product->current_stock,
                'threshold' => $product->low_stock_threshold,
            ],
            'status' => 'active',
        ]);
    }

    /**
     * مسح دوري (يستدعى من cron):
     * - فحص كل Batches القريبة من الانتهاء/المنتهية.
     * - تحديث حالتها + إنشاء تنبيهات.
     */
    public function scanExpiringBatches(Pharmacy $pharmacy, int $thresholdDays = self::NEAR_EXPIRY_DAYS): array
    {
        $results = ['expired' => 0, 'near_expiry' => 0];

        $batches = PharmacyBatch::whereHas('product', fn($q) => $q->where('pharmacy_id', $pharmacy->id))
            ->where('status', 'active')
            ->where('quantity_remaining', '>', 0)
            ->get();

        foreach ($batches as $batch) {
            $days = $batch->daysUntilExpiry();
            if ($days === null) continue;

            if ($days < 0) {
                // منتهي
                $batch->update(['status' => 'expired']);
                $this->ensureAlert($batch, 'expired', 'critical',
                    "Batch منتهي من {$batch->product->trade_name} (انتهى قبل " . abs($days) . " يوم)");
                $results['expired']++;

                // اخصم من current_stock للمنتج
                $product = $batch->product;
                $newStock = MoneyService::sub((string)$product->current_stock, (string)$batch->quantity_remaining);
                $product->update(['current_stock' => max(0, (float)$newStock)]);
            } elseif ($days <= $thresholdDays) {
                // قريب من الانتهاء
                $this->ensureAlert($batch, 'near_expiry', $days <= 7 ? 'critical' : 'warning',
                    "{$batch->product->trade_name} ينتهي خلال {$days} يوم (Batch: {$batch->batch_number})");
                $results['near_expiry']++;
            }
        }

        return $results;
    }

    private function ensureAlert(
        PharmacyBatch $batch, string $type, string $severity, string $message,
    ): PharmacyStockAlert {
        $existing = PharmacyStockAlert::where('batch_id', $batch->id)
            ->where('alert_type', $type)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            $existing->update(['message' => $message, 'severity' => $severity]);
            return $existing;
        }

        return PharmacyStockAlert::create([
            'pharmacy_id' => $batch->product->pharmacy_id,
            'product_id' => $batch->product_id,
            'batch_id' => $batch->id,
            'alert_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'details' => [
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'quantity_remaining' => (string)$batch->quantity_remaining,
            ],
            'status' => 'active',
        ]);
    }

    public function dismissAlert(PharmacyStockAlert $alert): PharmacyStockAlert
    {
        $alert->update(['status' => 'dismissed', 'dismissed_at' => now()]);
        return $alert->fresh();
    }

    /** ملخّص التنبيهات للصيدلية (للـ dashboard). */
    public function summary(Pharmacy $pharmacy): array
    {
        $base = PharmacyStockAlert::where('pharmacy_id', $pharmacy->id)
            ->where('status', 'active');

        return [
            'total_active' => (clone $base)->count(),
            'critical' => (clone $base)->where('severity', 'critical')->count(),
            'warning' => (clone $base)->where('severity', 'warning')->count(),
            'by_type' => [
                'low_stock' => (clone $base)->where('alert_type', 'low_stock')->count(),
                'near_expiry' => (clone $base)->where('alert_type', 'near_expiry')->count(),
                'expired' => (clone $base)->where('alert_type', 'expired')->count(),
            ],
        ];
    }
}
