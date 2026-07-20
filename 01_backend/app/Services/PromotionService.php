<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-PROMOTIONS-001 — تقييم وتطبيق الخصومات.
 *
 *   evaluate() — يعيد أفضل خصم قابل للتطبيق على مبلغ فاتورة (تلقائي أو بكوبون).
 *   consume()  — يزيد عدّاد الاستخدام لحظة البيع (يحترم الحدّ) بأمان تزامني.
 */
class PromotionService
{
    /**
     * أفضل خصم منطبق. الكوبون (code) إن مُرِّر يطابق كوبوناً بالكود؛ وإلا تُقيَّم
     * العروض التلقائية (بلا كود). يعيد ['discount','promotion','label'] أو null.
     */
    public function evaluate(User $merchant, float $subtotal, ?string $code = null): ?array
    {
        if ($subtotal <= 0) return null;

        $q = Promotion::where('merchant_user_id', $merchant->id)->where('is_active', true);
        $now = now();
        $q->where(function ($w) use ($now) {
            $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
        })->where(function ($w) use ($now) {
            $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
        });

        if ($code !== null && trim($code) !== '') {
            $q->whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))]);
        } else {
            $q->where(function ($w) {
                $w->whereNull('code')->orWhere('code', '');
            });
        }

        $candidates = $q->get()->filter(function (Promotion $p) use ($subtotal) {
            if ((float) $p->min_order_amount > $subtotal) return false;
            if ($p->usage_limit !== null && $p->used_count >= $p->usage_limit) return false;
            return true;
        });

        $best = null;
        foreach ($candidates as $p) {
            $discount = $this->discountFor($p, $subtotal);
            if ($discount <= 0) continue;
            if ($best === null || $discount > $best['discount']) {
                $best = ['discount' => $discount, 'promotion' => $p, 'label' => $p->name];
            }
        }
        if ($best === null) return null;
        // الخصم لا يتجاوز المبلغ
        $best['discount'] = min($best['discount'], $subtotal);
        $best['discount'] = round($best['discount'], 2);
        return $best;
    }

    private function discountFor(Promotion $p, float $subtotal): float
    {
        if ($p->type === 'percent') {
            $d = $subtotal * ((float) $p->value / 100.0);
            if ($p->max_discount_amount !== null && (float) $p->max_discount_amount > 0) {
                $d = min($d, (float) $p->max_discount_amount);
            }
            return $d;
        }
        return (float) $p->value; // fixed
    }

    /** يستهلك استخداماً واحداً بأمان تزامني. يعيد false إن تجاوز الحدّ. */
    public function consume(User $merchant, int $promotionId): bool
    {
        return (bool) DB::transaction(function () use ($merchant, $promotionId) {
            $p = Promotion::where('id', $promotionId)
                ->where('merchant_user_id', $merchant->id)
                ->lockForUpdate()->first();
            if (!$p) return false;
            if ($p->usage_limit !== null && $p->used_count >= $p->usage_limit) return false;
            $p->increment('used_count');
            return true;
        });
    }
}
