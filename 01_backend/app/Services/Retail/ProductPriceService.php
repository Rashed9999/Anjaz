<?php

namespace App\Services\Retail;

use App\Models\MerchantProduct;
use App\Models\Retail\ProductPriceVersion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٧ — **السعرُ نسخةٌ لا عمود**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **تعميمٌ لِما بُني في الوقود**، لا نسخٌ له: `FuelPriceService` يفعل هذا
 * بعينه للّتر، وبناؤه مرّتين يعني إصلاحَ عطلٍ في أحدهما وبقاءَه في الآخر.
 *
 * وكان `merchant_products.price` يُكتب فوقه. **وثلاثةُ أعطالٍ فيه:**
 *
 * ① **لا تاريخ.** «بكم كنّا نبيعه في رمضان؟» سؤالٌ بلا جواب.
 * ② **لا اعتماد.** موظّفٌ يخفض السعر ٤٠٪ ويبيع لمن يعرفه، ولا أثر.
 * ③ **لا سريان.** رفعُ الأسعار فجرَ السبت يُنفَّذ يدويّاً فجرَ السبت.
 *
 * **والمقترِحُ ليس المعتمِد** — وإلّا صار الاعتمادُ ختماً على النفس.
 */
class ProductPriceService
{
    public function propose(User $merchant, array $data, ?int $actorId = null): ProductPriceVersion
    {
        $product = MerchantProduct::where('id', (int) ($data['product_id'] ?? 0))
            ->where('merchant_user_id', $merchant->id)->first();
        if (! $product) {
            throw new DomainException('الصنف غير موجود');
        }

        $price = (string) ($data['price'] ?? '');
        if (! is_numeric($price) || bccomp($price, '0', 4) <= 0) {
            throw new DomainException('السعر يجب أن يكون موجباً');
        }

        $offer = $data['offer_price'] ?? null;
        if ($offer !== null && $offer !== '') {
            if (! is_numeric((string) $offer) || bccomp((string) $offer, '0', 4) <= 0) {
                throw new DomainException('سعر العرض يجب أن يكون موجباً');
            }
            if (bccomp((string) $offer, $price, 4) >= 0) {
                throw new DomainException('سعر العرض يجب أن يقلّ عن السعر');
            }
        } else {
            $offer = null;
        }

        $from = ! empty($data['effective_from'])
            ? Carbon::parse($data['effective_from']) : now();

        // **ولا سعرٌ يسري في الماضي.** وسريانٌ رجعيٌّ يعيد تسعير فواتيرَ
        // صدرت، فيختلف المطبوعُ عن المحسوب.
        if ($from->lt(now()->subMinute())) {
            throw new DomainException('السريان لا يكون في الماضي');
        }

        return ProductPriceVersion::create([
            'uuid' => (string) Str::uuid(),
            'merchant_user_id' => $merchant->id,
            'product_id' => $product->id,
            'location_id' => $data['location_id'] ?? null,
            'price' => $price,
            'offer_price' => $offer,
            'cost_price_at_time' => $product->cost_price,
            'effective_from' => $from,
            'status' => ProductPriceVersion::PROPOSED,
            'created_by_user_id' => $actorId ?? $merchant->id,
            'reason' => $data['reason'] ?? null,
        ]);
    }

    public function approve(User $actor, ProductPriceVersion $version): ProductPriceVersion
    {
        if ($version->status !== ProductPriceVersion::PROPOSED) {
            throw new DomainException('هذه النسخة حُسمت مسبقاً');
        }

        // **المقترِحُ ليس المعتمِد** — إلّا المالكَ نفسَه، فهو الطرفان.
        if ((int) $version->created_by_user_id === (int) $actor->id
            && (int) $version->merchant_user_id !== (int) $actor->id) {
            throw new DomainException('من اقترح السعر لا يعتمده');
        }

        $version->update([
            'status' => ProductPriceVersion::APPROVED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);

        // سريانُه الآن أو مضى وقتُه — فليُفعَّل فوراً.
        if ($version->effective_from->lte(now())) {
            return $this->activate($version->fresh());
        }

        return $version->fresh();
    }

    public function reject(User $actor, ProductPriceVersion $version, string $reason): ProductPriceVersion
    {
        if ($version->status !== ProductPriceVersion::PROPOSED) {
            throw new DomainException('هذه النسخة حُسمت مسبقاً');
        }

        $version->update([
            'status' => ProductPriceVersion::REJECTED,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
            'reason' => trim($reason) ?: $version->reason,
        ]);

        return $version->fresh();
    }

    /**
     * التفعيل — **ويُغلق سابقُه بتاريخه**.
     *
     * فنسختان ساريتان في اللحظة نفسِها تجعلان السعرَ يختلف بحسب أيّهما
     * قُرئت أوّلاً.
     */
    public function activate(ProductPriceVersion $version): ProductPriceVersion
    {
        if (! in_array($version->status, [
            ProductPriceVersion::APPROVED, ProductPriceVersion::ACTIVE,
        ], true)) {
            throw new DomainException('لا تُفعَّل نسخةٌ غير معتمَدة');
        }

        return DB::transaction(function () use ($version) {
            ProductPriceVersion::where('product_id', $version->product_id)
                ->where('id', '!=', $version->id)
                ->where('status', ProductPriceVersion::ACTIVE)
                ->update([
                    'status' => ProductPriceVersion::EXPIRED,
                    'effective_to' => $version->effective_from,
                ]);

            $version->update(['status' => ProductPriceVersion::ACTIVE]);

            // **المرآة**: `merchant_products.price` يقرؤه الكاشيرُ والتقارير
            // اليوم. يُحدَّث ويبقى مشتقّاً لا مصدراً.
            MerchantProduct::where('id', $version->product_id)->update([
                'price' => $version->price,
                'offer_price' => $version->offer_price,
            ]);

            return $version->fresh();
        });
    }

    /**
     * تفعيلُ ما حان وقتُه — **يُنادى من أمرٍ مجدول**.
     *
     * ولولاه صار «السريان» حقلاً يُملأ ولا يفعل شيئاً.
     */
    public function activateDue(): int
    {
        $due = ProductPriceVersion::where('status', ProductPriceVersion::APPROVED)
            ->where('effective_from', '<=', now())->get();

        $n = 0;
        foreach ($due as $v) {
            try {
                $this->activate($v);
                $n++;
            } catch (\Throwable $e) {
                logger()->warning('price activate failed: ' . $e->getMessage());
            }
        }

        return $n;
    }

    /** تاريخُ سعرِ صنف — **وبه يُجاب «بكم كنّا نبيعه؟»**. */
    public function history(User $merchant, int $productId, int $limit = 30): array
    {
        return ProductPriceVersion::where('merchant_user_id', $merchant->id)
            ->where('product_id', $productId)
            ->orderByDesc('effective_from')->limit($limit)->get()
            ->map(fn (ProductPriceVersion $v) => [
                'uuid' => $v->uuid,
                'price' => (string) $v->price,
                'offer_price' => $v->offer_price !== null ? (string) $v->offer_price : null,
                'cost_at_time' => $v->cost_price_at_time !== null
                    ? (string) $v->cost_price_at_time : null,
                'margin_at_time' => ($v->cost_price_at_time !== null
                        && bccomp((string) $v->cost_price_at_time, '0', 4) > 0)
                    ? bcsub((string) $v->price, (string) $v->cost_price_at_time, 4)
                    // **«غير معروف» لا صفر** (القاعدة ٧).
                    : null,
                'effective_from' => $v->effective_from?->toIso8601String(),
                'effective_to' => $v->effective_to?->toIso8601String(),
                'status' => $v->status,
                'status_ar' => $v->statusAr(),
                'reason' => $v->reason,
            ])->all();
    }
}
