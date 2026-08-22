<?php

namespace App\Services\Vertical;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-VERTICAL-BOOTSTRAP-001 — **القطاعُ يُبنى مع الحساب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **العطل الذي وُلدت منه:** أنشأ صاحبُ المشروع حساب «محطّة وقود» من
 * لوحة الإدارة، ثمّ فتح التطبيق فوجد:
 *
 *     ⚠️ تعذَّر إتمام العملية
 *        لا توجد محطة مرتبطة بهذا الحساب
 *
 * واللوحةُ كتبت `business_type = fuel` في `merchant_profiles` **ولم
 * تُنشئ صفَّ `fuel_stations`**. فالملفُّ يقول «محطّة» والقطاعُ بلا سجلّ.
 *
 * ولا خطأَ في أيّ سجلّ: الإنشاءُ نجح، والدخولُ نجح، والشاشةُ فتحت —
 * **ثمّ رفضت**. وهو نمطُ العطل الأكثر تكراراً في المشروع بصورةٍ جديدة:
 * ليست شاشةً بلا رابط، بل **حساباً بلا الشيء الذي أُنشئ من أجله**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا خدمةٌ واحدةٌ لا سطرٌ في اللوحة:**
 *
 * الحساباتُ تُنشأ من ثلاثة أبواب — لوحةُ الإدارة، والتسجيلُ الذاتيّ،
 * وأمرُ حسابات العرض. وسطرٌ في أحدها يترك البابين الآخرين على العطل
 * نفسه. **والقطاعُ يُبنى حيثما يُكتب `business_type`.**
 *
 * وهي **آمنةُ التكرار**: كلُّ خدمةٍ قطاعيّةٍ تحتها `getOrCreate`، فنداءٌ
 * ثانٍ لا يُنشئ ثانياً.
 *
 * ولا تُعطِّل: فشلُ تهيئة قطاعٍ لا يجوز أن يمنع إنشاء الحساب — والحسابُ
 * يعمل بلا قطاعه (محفظةٌ وتحويلات)، **والقطاعُ يُبنى عند أوّل فتح** لأنّ
 * المتحكّمات تنادي هذه الخدمة نفسَها.
 */
class VerticalBootstrapService
{
    /**
     * يضمن سجلَّ القطاع لهذا التاجر — ويُرجع ما بُني أو `null` إن كان
     * قطاعُه لا يحتاج سجلّاً.
     *
     * @return array{vertical:string, created:bool}|null
     */
    public function ensureFor(User $merchant): ?array
    {
        $type = MerchantProfile::where('user_id', $merchant->id)->value('business_type');

        if (! $type) {
            return null;
        }

        // ══════════════════════════════════════════════════════════════
        //  AMIAL-MERCHANT-USABLE-001 — **أدوارُ الموظّفين تُولَد مع الحساب.**
        //
        //  قالها صاحبُ المشروع: «عند إنشاء تاجر يُنشأ دورُه وهو مالك،
        //  ثمّ المالكُ هو من يُنشئ صلاحيّة موظّفيه».
        //
        //  **والمِلكيّةُ قائمةٌ بالفعل**: `MerchantPermissionService::isOwner`
        //  تقيسها من الهويّة — صاحبُ الحساب يملك كلَّ صلاحيّات قطاعه بلا
        //  صفِّ دور. فذاك الشقُّ سليم.
        //
        //  **والناقصُ هو الشقُّ الثاني**: `seedFuelRoles` مبنيّةٌ منذ
        //  جولات، ولا تُنادى إلّا من زرٍّ في شاشة «الأدوار». فمن أراد أن
        //  يُسند دوراً لكاشيره يفتح الشاشة فيجدها فارغة، وعليه أن يعرف
        //  أنّ في مكانٍ ما زرَّ «إنشاء الأدوار الافتراضيّة».
        //
        //  فتُزرع مع الحساب. **وهي آمنةُ التكرار** ولا تكتب فوق تعديلات
        //  المالك: `seedRoles` تتخطّى كلَّ دورٍ موجودٍ برمزه.
        $this->seedRolesFor($merchant, $type);

        try {
            return match ($type) {
                A::BIZ_FUEL => $this->fuel($merchant),
                A::BIZ_PHARMACY => $this->pharmacy($merchant),
                A::BIZ_WHOLESALE => $this->wholesale($merchant),

                // **والباقي لا يحتاج سجلّاً — ويُقال ذلك صراحةً.**
                //
                // التجزئةُ والبيعُ السريع والمطعم تعمل على جداولٍ عامّة
                // (`merchant_products` و`merchant_sales`)، وموقعُها
                // الافتراضيّ يُنشأ عند أوّل حركةِ مخزون في `StockService`.
                // فالصمتُ هنا قرارٌ لا إهمال.
                default => null,
            };
        } catch (\Throwable $e) {
            // **لا يُعطَّل إنشاءُ الحساب بفشل تهيئة قطاع.**
            // والقطاعُ يُبنى عند أوّل فتحٍ للشاشة — المتحكّمات تنادي هذه
            // الخدمة نفسَها.
            Log::warning('VerticalBootstrap failed', [
                'merchant_user_id' => $merchant->id,
                'business_type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function fuel(User $m): array
    {
        $existed = \App\Models\FuelStation::where('merchant_user_id', $m->id)->exists();

        app(\App\Services\FuelStationService::class)->getOrCreateStation($m);

        return ['vertical' => A::BIZ_FUEL, 'created' => ! $existed];
    }

    private function pharmacy(User $m): array
    {
        $existed = \App\Models\Pharmacy::where('merchant_user_id', $m->id)->exists();

        app(\App\Services\PharmacyService::class)->getOrCreatePharmacy($m);

        return ['vertical' => A::BIZ_PHARMACY, 'created' => ! $existed];
    }

    private function wholesale(User $m): array
    {
        $existed = \App\Models\WholesaleBusiness::where('merchant_user_id', $m->id)->exists();

        app(\App\Services\WholesaleService::class)->getOrCreateBusiness($m);

        return ['vertical' => A::BIZ_WHOLESALE, 'created' => ! $existed];
    }

    /**
     * أدوارُ الموظّفين الافتراضيّة لهذا النشاط.
     *
     * ولا تُعطِّل إنشاءَ الحساب إن فشلت: المالكُ يملك قطاعَه بهويّته،
     * ويستطيع زرعَها بنفسه من شاشة «الأدوار».
     */
    private function seedRolesFor(User $merchant, string $type): void
    {
        try {
            $perm = app(\App\Services\Merchant\MerchantPermissionService::class);

            // AMIAL-VERTICAL-RBAC-001 — **لكلّ قطاعٍ قالبُه.**
            //
            // كان هنا `default => seedRetailRoles`، وتعليقُه يقول: «ولا
            // تُخترع قائمةٌ لكلّ نشاطٍ قبل أن تُكتب صلاحيّاتُه».
            // **وقد كُتبت الآن** — للصيدليّة والجملة والمطعم فهرسُ أفعالٍ
            // وقوالبُ أدوارٍ بأسماء وظائفها.
            //
            // ويبقى البيعُ السريع على أدوار التجزئة **عن قصد**: هو تجزئةٌ
            // بلا مخزونٍ ولا فروع، ويعمل على الجداول نفسِها، فقالبٌ ثانٍ
            // بالأسماء نفسِها تكرارٌ لا فصل.
            match ($type) {
                A::BIZ_FUEL => $perm->seedFuelRoles($merchant),
                A::BIZ_PHARMACY => $perm->seedPharmacyRoles($merchant),
                A::BIZ_WHOLESALE => $perm->seedWholesaleRoles($merchant),
                A::BIZ_RESTAURANT => $perm->seedRestaurantRoles($merchant),
                default => $perm->seedRetailRoles($merchant),
            };
        } catch (\Throwable $e) {
            Log::warning('VerticalBootstrap: role seeding failed', [
                'merchant_user_id' => $merchant->id,
                'business_type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** أيحتاج هذا النشاطُ سجلَّ قطاعٍ خاصّاً به؟ */
    public static function needsRecord(?string $businessType): bool
    {
        return in_array($businessType, [A::BIZ_FUEL, A::BIZ_PHARMACY, A::BIZ_WHOLESALE], true);
    }
}
