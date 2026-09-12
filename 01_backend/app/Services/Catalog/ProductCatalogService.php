<?php

namespace App\Services\Catalog;

use App\Models\MerchantProduct;
use App\Models\User;
use App\Services\MoneyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-CATALOG-001 — الكتالوج المشترك: مفتوحٌ بمراجعة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا «مفتوحٌ بمراجعة» لا «الإدارة وحدها»:**
 *
 * بقّالةٌ واحدةٌ فيها ألفا صنف. وعشرون تاجراً يعني عشراتِ الآلاف. وموظّفٌ
 * واحدٌ لا يلحق — **فيبقى الكتالوجُ فارغاً ويعود التجّار للإدخال اليدويّ،
 * فيكون قد بُني ما لا يُستعمل.**
 *
 * فالكتالوجُ ينمو من التجّار: من أدخل باركوداً مجهولاً باسمٍ صار اقتراحاً
 * يراه من بعده. **والتجربةُ بعشرين تاجراً لا تستفيد من الكتالوج — هي
 * التي تبنيه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما يُفسده: الباركود الداخليّ.**
 *
 * المتاجرُ تطبع ملصقاتِها للخضار واللحوم والأصناف السائبة، وتبدأ بـ`2`
 * (نطاقٌ محجوزٌ للاستعمال الداخليّ في معيار EAN). **والرقمُ نفسُه يعني
 * «كيلو طماطم» عند تاجرٍ و«لحم غنم» عند آخر.**
 *
 * فلو دخلت الكتالوجَ لأفسدته من أوّل يوم، ولصار الماسحُ يقترح أسماءً
 * خاطئة — **وهو أسوأ من ألّا يقترح شيئاً**: الخطأُ الصامتُ يُصدَّق.
 *
 * والمنعُ هنا في الخدمة لا في متحكّم: **متحكّمٌ ثانٍ يُكتب غداً ينسى
 * الفحص، والخدمةُ لا تُنسى.**
 */
class ProductCatalogService
{
    /**
     * أطوالُ الباركود المعياريّة: EAN-8 · UPC-A · EAN-13.
     * وما سواها إمّا داخليٌّ وإمّا خطأُ إدخال.
     */
    private const GLOBAL_LENGTHS = [8, 12, 13, 14];

    /**
     * **هل يصلح هذا الباركود للكتالوج العامّ؟**
     *
     * يُرجع سببَ الرفض نصّاً، أو `null` حين يصلح.
     */
    public function rejectionReason(string $barcode): ?string
    {
        $b = trim($barcode);

        if ($b === '' || !ctype_digit($b)) {
            return 'الباركود ليس أرقاماً — لا يدخل الكتالوج المشترك';
        }

        if (!in_array(strlen($b), self::GLOBAL_LENGTHS, true)) {
            return 'طول الباركود غير معياريّ — يبقى في متجرك وحده';
        }

        // **النطاق الداخليّ.** يبقى محلّيّاً ولا يُرفع.
        if (str_starts_with($b, '2')) {
            return 'باركود داخليّ (يبدأ بـ٢) — معناه يختلف من متجر لآخر، فيبقى في متجرك';
        }

        return null;
    }

    private function isGlobal(string $barcode): bool
    {
        return $this->rejectionReason($barcode) === null;
    }

    /**
     * البحثُ عن صنفٍ في الكتالوج.
     *
     * **والمرفوضُ لا يُرجَع أبداً** — صنفٌ رفضته الإدارة لا يُقترح ثانيةً،
     * وإلّا صارت المراجعةُ بلا أثر.
     */
    public function find(string $barcode): ?object
    {
        if (!$this->isGlobal($barcode)) {
            return null;
        }

        return DB::table('product_catalog_entries')
            ->where('barcode', trim($barcode))
            ->whereNull('deleted_at')
            ->whereIn('status', ['verified', 'proposed'])
            ->first();
    }

    /**
     * AMIAL-CATALOG-ADOPT-001 — **يُتبنّى الصنفُ بضغطةٍ لا بإدخالٍ يدويّ.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الاتّفاقُ المسبق** كما قاله صاحبُ المشروع: «إضافة منتج مع باركود
     * ومعلومات، ثمّ يستطيع التاجرُ **تحميلَه** إلى منتجاته بدل إضافته
     * يدويّاً».
     *
     * وكان نصفُه مبنيّاً: `catalog/lookup` تملأ الحقولَ عند الإنشاء، فيبقى
     * على التاجر أن يكتب السعرَ ويضغط حفظ. **والنصفُ الناقصُ هو الضغطة
     * الواحدة** — ومعها العدّادُ الذي يقيس نفعَ الكتالوج.
     *
     * و`adoption_count` عمودٌ قائمٌ **يزيده اقتراحُ تاجرٍ ولا يزيده تبنٍّ**
     * — فكان يقيس مَن كتب لا مَن انتفع.
     *
     * **والسعرُ من التاجر لا من الكتالوج**: الكتالوجُ بلا عمود سعرٍ عمداً،
     * فرؤيةُ تاجرٍ سعرَ منافسه تسريبٌ تجاريّ. فيُطلب السعرُ في التبنّي.
     * ══════════════════════════════════════════════════════════════════
     *
     * @throws \DomainException إن لم يوجد الصنفُ أو كان عند التاجر أصلاً
     */
    public function adopt(User $merchant, string $barcode, array $overrides = []): MerchantProduct
    {
        $barcode = trim($barcode);
        $entry = $this->find($barcode);

        if (! $entry) {
            // **ويُقال سببُ الرفض** — «غير موجود» على باركودٍ داخليٍّ
            // تُرسل التاجرَ يعيد المسحَ ظانّاً أنّ الشبكة تعطّلت.
            throw new \DomainException(
                $this->rejectionReason($barcode) ?? 'لا يوجد هذا الصنف في الكتالوج',
            );
        }

        // **ولا يُتبنّى مرّتين.** ولولا هذا لأنتجت الضغطتان صنفين بالباركود
        // نفسِه، فيُمسح فيُوجد اثنان ولا يُعرف أيُّهما يُباع.
        $existing = MerchantProduct::where('merchant_user_id', $merchant->id)
            ->where('barcode', $barcode)->first();

        if ($existing) {
            throw new \DomainException('هذا الصنف عندك بالفعل: ' . $existing->name);
        }

        return DB::transaction(function () use ($merchant, $entry, $barcode, $overrides) {
            $product = MerchantProduct::create([
                'merchant_user_id' => $merchant->id,
                'name' => $entry->name,
                'category' => $entry->category,
                'barcode' => $barcode,
                'price' => MoneyService::normalize((string) ($overrides['price'] ?? 0)),
                'cost_price' => MoneyService::normalize((string) ($overrides['cost_price'] ?? 0)),
                'quantity' => (string) ($overrides['quantity'] ?? 0),
                'is_active' => true,
            ]);

            // **العدّادُ يقيس النفعَ لا الكتابة.**
            DB::table('product_catalog_entries')->where('id', $entry->id)
                ->increment('adoption_count');

            return $product->fresh();
        });
    }

    /**
     * تاجرٌ يُدخل صنفاً — فيُسجَّل اقتراحُه.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُوقف حفظَ منتج التاجر أبداً.**
     *
     * فالكتالوجُ خدمةٌ جانبيّة: لو فشل تسجيلُ الاقتراح لأيّ سبب، **يبقى
     * منتجُ التاجر محفوظاً**. ومن ربط بيعَه بنجاح ميزةٍ مساعدةٍ أوقف
     * متجراً كاملاً لأجل اسمٍ في جدول.
     *
     * @return string|null سببُ عدم التسجيل (للعرض عند الحاجة)، أو null.
     */
    public function suggest(string $barcode, string $name, User $merchant, ?string $category = null, ?string $unit = null): ?string
    {
        $reason = $this->rejectionReason($barcode);

        if ($reason !== null) {
            return $reason;
        }

        $b = trim($barcode);
        $n = trim($name);

        if ($n === '') {
            return 'الاسم فارغ';
        }

        try {
            DB::transaction(function () use ($b, $n, $merchant, $category, $unit) {
                $entry = DB::table('product_catalog_entries')->where('barcode', $b)->first();

                if (!$entry) {
                    // أوّلُ من رآه — يصير مدخلاً مقترَحاً.
                    DB::table('product_catalog_entries')->insert([
                        'entry_ulid' => (string) Str::ulid(),
                        'barcode' => $b,
                        'name' => $n,
                        'category' => $category,
                        'unit' => $unit,
                        'status' => 'proposed',
                        'adoption_count' => 1,
                        'proposed_by_user_id' => $merchant->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($entry->deleted_at === null && $entry->status !== 'rejected') {
                    // **وعدّادُ التبنّي يُحسب من الاستعمال** — وهو ما يقول
                    // للإدارة أيُّ الأسماء أشيع حين تتعارض.
                    DB::table('product_catalog_entries')
                        ->where('id', $entry->id)
                        ->increment('adoption_count');
                }

                // الاقتراحُ يُحفظ دائماً — حتّى لو طابق الاسمَ المعتمد،
                // فهو دليلُ تبنٍّ لا مجرّدُ خلاف.
                DB::table('product_catalog_suggestions')->updateOrInsert(
                    ['barcode' => $b, 'merchant_user_id' => $merchant->id],
                    [
                        'name' => $n,
                        'category' => $category,
                        'unit' => $unit,
                        'status' => 'pending',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Catalog suggestion failed (non-fatal)', [
                'barcode' => $b, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        return null;
    }

    /**
     * الأسماءُ المتنافسة على باركودٍ واحد.
     *
     * **والتعارضُ يُعرض ولا يُبتلع:** «شاي ليبتون ١٠٠ كيس» و«ليبتون أحمر
     * كبير» كلاهما صحيح، والإدارةُ تختار — ولا تُحذف واحدةٌ صامتةً.
     */
    public function conflicts(string $barcode): array
    {
        return DB::table('product_catalog_suggestions as s')
            ->leftJoin('users as u', 'u.id', '=', 's.merchant_user_id')
            ->where('s.barcode', trim($barcode))
            ->select('s.id', 's.name', 's.category', 's.unit', 's.created_at',
                     'u.f_name', 'u.l_name', 'u.phone')
            ->orderBy('s.created_at')
            ->get()->all();
    }

    /** عددُ الباركودات التي فيها أكثرُ من اسم. */
    public function conflictCount(): int
    {
        return (int) DB::table(DB::raw('(
            SELECT barcode FROM product_catalog_suggestions
            GROUP BY barcode HAVING COUNT(DISTINCT name) > 1
        ) c'))->count();
    }
}
