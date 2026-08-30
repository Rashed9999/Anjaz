<?php

namespace App\Services;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\PosUser;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use App\Support\Access\CapabilityRegistry;

/**
 * CRITICAL-001 — Feature Access Service.
 *
 * الخدمة الجوهرية التي تقرّر ما يراه كل مستخدم.
 *
 * المنطق:
 *   features = roleBase(role)
 *            ∪ businessTypeFeatures(business_type)
 *            ∪ planFeatures(plan)
 *            ∪ verificationFeatures(verification_level)
 *            ∪ extra_features (من الأدمن)
 */
class FeatureAccessService
{
    /**
     * يُرجع كل البيانات اللازمة للواجهة:
     *   - role
     *   - verification_level
     *   - business_type
     *   - subscription_plan
     *   - subscription_expires_at
     *   - features[]
     *   - limits{}
     *   - labels (للعرض)
     */
    public function accessFor(User $user): array
    {
        $role = $user->role ?? A::ROLE_USER;
        $verificationLevel = $user->verification_level ?? A::VERIFICATION_BASIC;

        $merchantProfile = null;
        $businessType = null;
        $plan = A::PLAN_FREE;
        $extraFeatures = [];
        $expiresAt = null;
        $notes = null;

        // ══════════════════════════════════════════════════════════════
        // AMIAL-ACTOR-001 — **موظّفُ نقطة البيع يرث صنفَ تاجره، لا يبدأ فارغاً.**
        //
        // كان هذا الشرطُ يقبل `ROLE_MERCHANT` وحده. وموظّفُ نقطة البيع
        // دورُه `'pos'` — **وهو ليس في `ALL_ROLES` أصلاً**. فكان يرجع له:
        //
        //     business_type = null · plan = free · features = الأساس وحده
        //
        // أي أنّ كاشيرَ محطّة الوقود يفتح التطبيق فلا يجد ميزةَ وقودٍ
        // واحدة — **والخادمُ يقول إنّه بلا صنفٍ وبلا اشتراك**. ولا خطأ
        // في أيّ سجلّ: الردُّ ٢٠٠ وقائمةُ الميزات صحيحةُ الشكل، فارغةُ
        // المعنى.
        //
        // **والفاعلُ يُصرَّح به الآن** (`actor`): مالكٌ أم موظّفُ نقطة
        // بيع. فشاشةُ الإدارة غيرُ شاشة البيع، ولا يُبنى الفرقُ على تخمين
        // التطبيق. (القاعدة الثامنة: الهويّة تحدّد النطاق.)
        $actor = 'customer';
        $posUser = null;
        $ownerId = null;

        if ($role === A::ROLE_MERCHANT) {
            $actor = 'owner';
            $ownerId = $user->id;
            $merchantProfile = MerchantProfile::where('user_id', $user->id)->first();
            if ($merchantProfile) {
                $businessType = $merchantProfile->business_type;
                $plan = A::canonicalPlan($merchantProfile->subscription_plan);
                $extraFeatures = is_array($merchantProfile->extra_features)
                    ? $merchantProfile->extra_features : [];
                $expiresAt = $merchantProfile->subscription_expires_at?->toIso8601String();
                $notes = $merchantProfile->subscription_notes;

                // CRITICAL-001-PLANS — فحص انتهاء الاشتراك
                // إن انتهى التاريخ والـ plan ليس FREE، يعود تلقائياً لـ FREE
                if ($plan !== A::PLAN_FREE
                    && $merchantProfile->subscription_expires_at !== null
                    && $merchantProfile->subscription_expires_at->isPast()) {
                    $plan = A::PLAN_FREE;
                }
            }
        }

        // **وموظّفُ نقطة البيع: يرث ثمّ يُقيَّد.**
        //
        // يرث صنفَ التاجر وخطّتَه — فبلا الوراثة لا يرى كاشيرَ الوقود.
        // ثمّ **تُقصُّ ميزاتُه بصلاحيّاته** المحفوظة في `pos_users.permissions`:
        // فمن أُعطي البيعَ وحده لا يرى الأسعارَ ولا الورديّاتِ ولا التقارير.
        //
        // **وصلاحيّاتٌ فارغةٌ تعني البيعَ وحده لا كلَّ شيء** — فالافتراضيُّ
        // الآمن في المال أضيقُ الدائرتين. (وموظّفٌ نُسي ضبطُه لا يفتح
        // خزنةَ صاحبه.)
        if ($role === self::ROLE_POS) {
            $posUser = PosUser::where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if ($posUser) {
                $actor = 'pos';
                $ownerId = $posUser->merchant_user_id;

                $merchantProfile = MerchantProfile::where('user_id', $ownerId)->first();

                if ($merchantProfile) {
                    $businessType = $merchantProfile->business_type;
                    $plan = A::canonicalPlan($merchantProfile->subscription_plan);

                    if ($plan !== A::PLAN_FREE
                        && $merchantProfile->subscription_expires_at !== null
                        && $merchantProfile->subscription_expires_at->isPast()) {
                        $plan = A::PLAN_FREE;
                    }
                }
            }
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-ACTOR-002 — **وموظّفُ الأدوار يرث كذلك.**
        //
        // للمشروع طريقان لتوظيف عاملٍ تحت تاجر، وقد افترقا بصمت:
        //
        //   `pos_users`          كاشيرٌ برقم نقطة بيع     ← يُورَّث أعلاه
        //   `merchant_user_roles` موظّفٌ بدورٍ (‏محطّةُ وقودٍ مثلاً) ← **لا**
        //
        // فالثاني كان يُقرأ «بلا صنفِ نشاطٍ وبلا اشتراك». ولم يظهر ذلك
        // ما دامت مساراتُ الوقود بلا حارسِ قدرة: الصلاحيّةُ وحدَها كانت
        // تحكم فتردّ ٤٢٢. **وأوّلَ ما حُرست `fuel_variance` بقدرتها ردّ
        // الخادمُ ٤٠٤ «نشاطٌ غيرُ منطبق»** على موظّفٍ في محطّةِ وقودٍ
        // فعليّة — أي أنّ الحارسَ الجديدَ كشف ثغرةً قديمةً في الوراثة.
        //
        // ولا يُمنح الموظّفُ شيئاً زائداً: الصلاحيّاتُ تُضيَّق بعدها
        // بـ`restrictToPosPermissions` لموظّف نقطة البيع، وبمصفوفة أدوار
        // التاجر لموظّف الأدوار — وهذه ترث **الاشتراكَ والصنف** لا الإذن.
        if ($actor === 'customer' && $ownerId === null) {
            $assignment = DB::table('merchant_user_roles')
                ->where('user_id', $user->id)
                ->whereNotNull('merchant_user_id')
                ->first();

            if ($assignment !== null) {
                $actor = 'staff';
                $ownerId = (int) $assignment->merchant_user_id;

                $ownerProfile = MerchantProfile::where('user_id', $ownerId)->first();

                if ($ownerProfile) {
                    $businessType = $ownerProfile->business_type;
                    $plan = A::canonicalPlan($ownerProfile->subscription_plan);

                    if ($plan !== A::PLAN_FREE
                        && $ownerProfile->subscription_expires_at !== null
                        && $ownerProfile->subscription_expires_at->isPast()) {
                        $plan = A::PLAN_FREE;
                    }
                }
            }
        }

        // الوراثةُ تُحسب بصنف التاجر وخطّته أيّاً كان الفاعل.
        $inheritRole = in_array($actor, ['owner', 'pos', 'staff'], true) ? A::ROLE_MERCHANT : $role;

        $features = $this->resolveFeatures(
            $inheritRole, $verificationLevel, $businessType, $plan, $extraFeatures);

        if ($actor === 'pos') {
            $features = $this->restrictToPosPermissions($features, $posUser);
        }

        $limits = in_array($actor, ['owner', 'pos'], true)
            ? AccessPresets::planLimits($plan) : [];

        return [
            'role' => $role,

            // **الفاعلُ مصرَّحٌ به** — لا يُستنتج في التطبيق من غياب حقل.
            'actor' => $actor,
            'merchant_user_id' => $ownerId,
            'pos' => $posUser ? [
                'id' => $posUser->id,
                'pos_number' => $posUser->pos_number,
                'display_name' => $posUser->display_name,
                'permissions' => is_array($posUser->permissions) ? $posUser->permissions : [],
            ] : null,

            'verification_level' => $verificationLevel,
            'business_type' => $businessType,
            'business_type_label' => $businessType ? (A::BUSINESS_TYPE_LABELS[$businessType] ?? null) : null,
            'subscription_plan' => $plan,
            'subscription_plan_label' => A::PLAN_LABELS[$plan] ?? $plan,
            'subscription_price_sar' => A::PLAN_PRICES_SAR[$plan] ?? 0,
            'subscription_price_currency' => A::PLAN_PRICE_CURRENCY,
            'subscription_expires_at' => $expiresAt,
            'subscription_notes' => $notes,
            'features' => array_values($features),
            'limits' => $limits,
        ];
    }

    /**
     * دورُ موظّف نقطة البيع — يُنشئه `MerchantStaffController` بهذه القيمة
     * وليس في `ALL_ROLES`. يُثبَّت هنا بدل تكراره نصّاً.
     */
    private const ROLE_POS = 'pos';

    /**
     * ما يُسمح لموظّف نقطة البيع به من ميزات صاحبه.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والافتراضيُّ أضيقُ الدائرتين.** فموظّفٌ بلا صلاحيّاتٍ مضبوطة يبيع
     * ويطبع إيصالَه ولا شيءَ غير ذلك — لا أسعار، ولا ورديّات، ولا تقارير،
     * ولا موظّفون. **ومن نُسي ضبطُه لا يفتح خزنةَ صاحبه.**
     *
     * (وهذه ليست واجهةً تُخفي أزراراً: القائمةُ تُحسب في الخادم، ونقاطُ
     * النهاية تحرس نفسَها بوسائطها. الإخفاءُ راحةٌ للعين لا حماية.)
     *
     * @param  array<int,string>  $features
     * @return array<int,string>
     */
    private function restrictToPosPermissions(array $features, ?PosUser $pos): array
    {
        $granted = ($pos && is_array($pos->permissions)) ? $pos->permissions : [];

        // ما يبقى دائماً: البيعُ نفسُه وما لا يقوم بدونه.
        $always = [
            A::F_WALLET, A::F_NOTIFICATIONS, A::F_PROFILE,
            A::F_RECEIPTS, A::F_CASHIER, A::F_FUEL_POS,
            A::F_PHARMACY_POS, A::F_QUICK_SALE,
        ];

        return array_values(array_filter(
            $features,
            static fn (string $f): bool => in_array($f, $always, true)
                || in_array($f, $granted, true),
        ));
    }

    /**
     * يحسب قائمة Features النهائية بدمج كل المصادر.
     */
    public function resolveFeatures(
        string $role,
        string $verificationLevel,
        ?string $businessType,
        string $plan,
        array $extraFeatures = [],
    ): array {
        $features = [];

        // 1. أساس الدور
        foreach (AccessPresets::roleBase($role) as $f) $features[$f] = true;

        // 2. Business Type (للتجّار فقط)
        if ($role === A::ROLE_MERCHANT && $businessType !== null) {
            foreach (AccessPresets::businessTypeFeatures($businessType) as $f) $features[$f] = true;
        }

        // ══════════════════════════════════════════════════════════════
        // 3. Plan — **والباقةُ تفتح العمق، ونوعُ النشاط يقرّر الانطباق.**
        //
        // AMIAL-VERTICAL-SCOPE-001. سأل صاحبُ المشروع: «لماذا تاجرُ وقودٍ
        // لديه أصنافٌ ومخزون؟». وقِيس فكان محقّاً:
        //
        //     resolveFeatures(merchant, fuel, business) = ٣٩ ميزة
        //     ومنها: products · barcode · inventory · inventory_audit ·
        //            low_stock_alerts · purchases · quick_sale
        //
        // **وإعلانُ كلٍّ منها تجزئةٌ بنصّه**: صلاحيّاتُها
        // `retail.product.*` و`retail.stock.*`، وشاشاتُها `/retail`
        // و`/products`. تُمنَح لمحطّةِ وقودٍ لأنّ `planFeatures` قائمةٌ
        // مسطّحةٌ **لا تعرف القطاع**.
        //
        // وفوق `planFeatures` نفسِها كان مكتوباً: «نوع النشاط يحدد
        // الانطباق… ولا تمنح قدرة قطاعية لقطاع آخر» — **نيّةٌ مكتوبةٌ ولا
        // سطرَ ينفّذها**.
        //
        // ── وأخطرُ ما فيه أنّه طريقٌ مسدود ──
        //
        // الشاشةُ الوحيدةُ التي تبيع من ذلك الكتالوج **ترفض أن تُفتح له**:
        // `CashierPosScreen` تردّ حسابَ الوقود إلى `FuelSaleScreen` بحاجزٍ
        // قطاعيّ. فيُباع كتالوجٌ ومخزونٌ وباركود، ثمّ يُمنَع البيعُ منها —
        // كتالوجٌ يُملأ ولا يُفرَّغ.
        //
        // ── والآلةُ كانت مبنيّةً ولا تُنادى ──
        //
        // `Capability::appliesTo()` قائمةٌ، ويستعملها `EntitlementService`
        // في شاشة «قدراتي»، وتُعلنها عشرون قدرةً (‏`cashier` منها:
        // تجزئةٌ وجملةٌ فقط). **ولم يكن `resolveFeatures` يسألها قطّ.**
        //
        // ── والحكمُ من صاحب المشروع لا منّي ──
        //
        // «تدقيق شامل لقطاعات التجار»، جدولُ «لا يجوز أن يرى»:
        //     محطة وقود ⇒ «البيع السريع بدون التجزئة، كاشير منتجات عام»
        // ودليلُ تشغيل المحطّة: «القائمة ليست قالباً مشتركاً لكل التجار».
        //
        // **وما لا قدرةَ له في السجلّ يمرّ** — لا يُحذف بالشكّ. فبعضُ
        // الميزات أساسٌ بلا مدخلٍ في الكتالوج (وقد كُشف ذلك في
        // `payment_requests`)، وحذفُها بحجّة القطاع يُقفل ما ليس مقصوداً.
        // ══════════════════════════════════════════════════════════════
        if ($role === A::ROLE_MERCHANT) {
            foreach (AccessPresets::planFeatures($plan) as $f) {
                if (! self::planFeatureAppliesTo($f, $businessType)) {
                    continue;
                }
                $features[$f] = true;
            }

            // **وعمقُ القطاع لا يُصفَّى** — هو معرَّفٌ لقطاعه أصلاً،
            // وتصفيتُه بنفسِه تحصيلُ حاصلٍ يُخفي خطأً إن وقع.
            foreach (AccessPresets::verticalPlanFeatures($businessType, $plan) as $f) {
                $features[$f] = true;
            }
        }

        // 4. Verification
        foreach (AccessPresets::verificationFeatures($verificationLevel) as $f) $features[$f] = true;

        // 5. Extra (يُفعّلها الأدمن يدوياً)
        foreach ($extraFeatures as $f) {
            if (is_string($f)) $features[$f] = true;
        }

        return array_keys($features);
    }

    /**
     * **هل تنطبق ميزةُ باقةٍ على هذا النشاط؟**
     *
     * المصدرُ واحد: `Capability::appliesTo()` في سجلّ القدرات — وهو ما
     * تسأله شاشةُ «قدراتي» أصلاً. **ولا تُكتب هنا قائمةٌ ثانية**: قائمتان
     * تفترقان، وقد افترق الكتالوجان قبلُ (٤٧ في الخادم و٢٧ في الشاشة)
     * فقرأ التاجرُ مقاماً لا يُحسب من مصدره.
     *
     * **وبلا صنفِ نشاطٍ لا يُصفّى شيء**: تاجرٌ لم يختره بعد يُقاد إلى
     * شاشة الاختيار، وقصُّ ميزاته قبلها يُقفل حسابَه على فراغ.
     */
    private static function planFeatureAppliesTo(string $feature, ?string $businessType): bool
    {
        if ($businessType === null) {
            return true;
        }

        $cap = CapabilityRegistry::find($feature);

        // **قدرةٌ غيرُ معلَنةٍ في السجلّ تمرّ** — والغيابُ ليس منعاً.
        return $cap === null || $cap->appliesTo($businessType);
    }

    /** فحص سريع لميزة محدّدة. */
    public function hasFeature(User $user, string $feature): bool
    {
        $access = $this->accessFor($user);
        return in_array($feature, $access['features'], true);
    }

    /** Admin: تغيير خطّة تاجر. */
    public function updateMerchantPlan(
        MerchantProfile $merchant,
        string $newPlan,
        ?\DateTimeInterface $expiresAt = null,
        ?string $notes = null,
    ): MerchantProfile {
        if (!in_array($newPlan, A::ALL_PLANS, true)) {
            throw new \InvalidArgumentException("خطّة غير صحيحة: {$newPlan}");
        }
        $merchant->update([
            'subscription_plan' => $newPlan,
            'subscription_expires_at' => $expiresAt,
            'subscription_notes' => $notes,
        ]);
        return $merchant->fresh();
    }

    /** Admin: تغيير business_type تاجر. */
    public function updateBusinessType(MerchantProfile $merchant, ?string $type): MerchantProfile
    {
        if ($type !== null && !in_array($type, A::ALL_BUSINESS_TYPES, true)) {
            throw new \InvalidArgumentException("نوع نشاط غير صحيح: {$type}");
        }
        $merchant->update(['business_type' => $type]);
        return $merchant->fresh();
    }

    /** Admin: إضافة feature إضافية يدوياً (للحالات الاستثنائية). */
    public function addExtraFeature(MerchantProfile $merchant, string $feature): MerchantProfile
    {
        $current = is_array($merchant->extra_features) ? $merchant->extra_features : [];
        if (!in_array($feature, $current, true)) {
            $current[] = $feature;
            $merchant->update(['extra_features' => $current]);
        }
        return $merchant->fresh();
    }

    public function removeExtraFeature(MerchantProfile $merchant, string $feature): MerchantProfile
    {
        $current = is_array($merchant->extra_features) ? $merchant->extra_features : [];
        $merchant->update(['extra_features' => array_values(array_filter($current, fn($f) => $f !== $feature))]);
        return $merchant->fresh();
    }
}
