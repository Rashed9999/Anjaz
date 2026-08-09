<?php

namespace App\Services\Fuel;

use App\Models\Fuel\FuelPriceVersion;
use App\Models\FuelProduct;
use App\Models\User;
use App\Services\AuditService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٦ — الأسعارُ بالسريان والاعتماد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كان سليماً ويبقى:** `fuel_sales.price_per_liter` تلتقط السعرَ
 * لحظةَ البيع. فالعمليّاتُ التاريخيّة صادقةٌ أصلاً، ولا يُمسّ ذلك.
 *
 * **والناقصُ كان الحكمَ المسبق:** من اقترح، ومن اعتمد، ومن متى يسري،
 * ولماذا. و`fuel_price_history` أثرٌ بعد الفعل لا نسخةٌ قبله.
 *
 * وفصلُ الاقتراح عن الاعتماد ليس بيروقراطيّة: **السعرُ أخطرُ رقمٍ في
 * المحطّة**. من يملك تغييرَه وحدَه يستطيع أن يبيع بأقلّ ويأخذ الفرق،
 * ولا يظهر في أيّ جردٍ لأنّ اللترات مطابقة.
 */
class FuelPriceService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    /**
     * ① اقتراحُ سعرٍ — لا يسري حتّى يُعتمد.
     */
    public function propose(
        FuelProduct $product, User $actor, string $price, string $reason,
        ?\DateTimeInterface $effectiveFrom = null,
    ): FuelPriceVersion {
        if (bccomp($price, '0', 4) <= 0) {
            throw new DomainException('السعر يجب أن يكون موجباً');
        }

        if (mb_strlen(trim($reason)) < 3) {
            // سببٌ إلزاميّ: «تحديث رسميّ» أو «قرار وزاري» أو «تصحيح خطأ».
            // وبلا سببٍ يصير سجلُّ الأسعار قائمةَ أرقامٍ لا تاريخَ قرارات.
            throw new DomainException('اكتب سبب تغيير السعر — بلا سبب لا يُراجَع القرار');
        }

        $from = $effectiveFrom ?? now();

        return FuelPriceVersion::create([
            'fuel_product_id' => $product->id,
            'station_id' => $product->station_id,
            'price_per_liter' => $price,
            'effective_from' => $from,
            'status' => 'pending_approval',
            'created_by_user_id' => $actor->id,
            'reason' => trim($reason),
        ]);
    }

    /**
     * ② الاعتماد — **وهنا يسري السعر ويُختم سابقُه**.
     *
     * ولا يعتمد المقترحُ اقتراحَه: فصلُ اليدين هو كلُّ الفائدة.
     */
    public function approve(FuelPriceVersion $version, User $approver): FuelPriceVersion
    {
        if ($version->status !== 'pending_approval') {
            throw new DomainException('هذه النسخة ليست بانتظار الاعتماد');
        }

        if ((int) $version->created_by_user_id === (int) $approver->id) {
            throw new DomainException(
                'لا يعتمد مقترحُ السعر اقتراحَه — الاعتماد من شخصٍ آخر',
            );
        }

        return DB::transaction(function () use ($version, $approver) {
            // ختمُ النسخة السارية عند لحظة سريان الجديدة.
            FuelPriceVersion::where('fuel_product_id', $version->fuel_product_id)
                ->where('status', 'active')
                ->whereNull('effective_to')
                ->update([
                    'effective_to' => $version->effective_from,
                    'status' => 'superseded',
                ]);

            $version->update([
                'status' => 'active',
                'approved_by_user_id' => $approver->id,
                'approved_at' => now(),
            ]);

            // **والسعرُ المعروض يتبع النسخةَ السارية.** فعمودُ
            // `fuel_products.price_per_liter` صار مرآةً لا مصدراً — يُقرأ
            // في شاشة البيع السريعة، ويُكتب من هنا وحدَه.
            $product = FuelProduct::find($version->fuel_product_id);

            if ($product) {
                $old = (string) $product->price_per_liter;
                $product->update(['price_per_liter' => $version->price_per_liter]);

                \App\Models\FuelPriceHistory::create([
                    'fuel_product_id' => $product->id,
                    'station_id' => $product->station_id,
                    'changed_by_user_id' => $approver->id,
                    'old_price' => $old,
                    'new_price' => (string) $version->price_per_liter,
                    'delta' => bcsub((string) $version->price_per_liter, $old, 4),
                    'note' => $version->reason,
                    'created_at' => now(),
                ]);
            }

            $this->audit->record([
                'actor_type' => 'merchant',
                'actor_user_id' => $approver->id,
                'subject_type' => 'fuel_product',
                'subject_id' => (string) $version->fuel_product_id,
                'action' => 'FUEL_PRICE_APPROVED',
                'decision_code' => 'PRICE_APPROVE',
                'severity' => 'critical',
                'context' => [
                    'price' => (string) $version->price_per_liter,
                    'effective_from' => $version->effective_from?->toIso8601String(),
                    'proposed_by' => $version->created_by_user_id,
                    'reason' => $version->reason,
                ],
            ]);

            return $version->fresh();
        });
    }

    public function reject(FuelPriceVersion $version, User $actor, string $reason): FuelPriceVersion
    {
        if ($version->status !== 'pending_approval') {
            throw new DomainException('هذه النسخة ليست بانتظار الاعتماد');
        }

        $version->update([
            'status' => 'rejected',
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'reason' => $version->reason . ' | رفض: ' . $reason,
        ]);

        return $version->fresh();
    }

    /**
     * السعرُ السارّي في لحظةٍ ما — **من النسخ لا من العمود**.
     *
     * يُستعمل في التقارير التاريخيّة وفي مراجعة عمليّةٍ قديمة: «بكم كان
     * البنزين ليلةَ الثلاثاء؟» سؤالٌ لا يجيب عنه عمودٌ يُكتب فوقه.
     */
    public function priceAt(FuelProduct $product, \DateTimeInterface $moment): ?string
    {
        $v = FuelPriceVersion::where('fuel_product_id', $product->id)
            ->where('status', '!=', 'rejected')
            ->where('effective_from', '<=', $moment)
            ->where(function ($q) use ($moment) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $moment);
            })
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        return $v ? (string) $v->price_per_liter : null;
    }

    /** النسخُ المعلَّقة — يراها المعتمِد في لوحته. */
    public function pending(int $stationId): array
    {
        return FuelPriceVersion::where('station_id', $stationId)
            ->where('status', 'pending_approval')
            ->with('product:id,name')
            ->orderBy('effective_from')->get()
            ->map(fn (FuelPriceVersion $v) => [
                'id' => (int) $v->id,
                'product' => $v->product->name ?? '—',
                'price_per_liter' => (string) $v->price_per_liter,
                'effective_from' => $v->effective_from?->toIso8601String(),
                'reason' => $v->reason,
                'created_by' => (int) $v->created_by_user_id,
            ])->all();
    }
}
