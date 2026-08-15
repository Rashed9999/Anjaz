<?php

namespace App\Services\Access;

use App\Models\Access\MerchantCapabilityOverride;
use App\Models\Access\PlanCapability;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Services\Merchant\MerchantPermissionService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\Capability;
use App\Support\Access\CapabilityRegistry;

/**
 * AMIAL-ENTITLEMENTS-001 — **المُفتي الواحد: ما حالُ هذه القدرة لهذا الحساب؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والجوابُ أربعُ حالاتٍ لا اثنتان** — وهذا هو جوهرُ التصميم:
 *
 * | الحالة | لمن تُقال | وماذا يفعل |
 * |---|---|---|
 * | `available` | — | يعمل |
 * | `locked_by_plan` | **لصاحب المتجر** | يرقّي — ومعها اسمُ الباقة وسعرُها |
 * | `locked_by_role` | **للموظّف** | يطلب من مديره — ومعها اسمُ الصلاحيّة |
 * | `limit_reached` | لصاحب المتجر | يرقّي — ومعها الرقمان: المستعمَل والحدّ |
 * | `not_applicable` | — | قدرةُ قطاعٍ آخر: صيدليّةٌ لا ترى مضخّات الوقود |
 *
 * **وخلطُ الأوّلين هو العطل**: من ردّ «لا تملك صلاحية» على تاجرٍ باقتُه
 * ناقصة أرسله يبحث عن دورٍ يمنحه لنفسه ولن يجد. ومن ردّ «رقِّ باقتك»
 * على كاشيرٍ محدود الدور أرسل صاحبَ المتجر يدفع بلا سبب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وترتيبُ الحسم أربعُ طبقاتٍ، الأدنى يفوز:**
 *
 * ```
 * ① سجلُّ الشيفرة (minPlan)        ← الافتراضيّ
 * ② plan_capabilities              ← قرارُ المنصّة، يعلو على ①
 * ③ merchant_capability_overrides  ← قرارُ حالةٍ بعينها، يعلو على ②
 * ④ core()                          ← لا يُقفَل بشيءٍ ممّا سبق
 * ```
 */
class EntitlementService
{
    /** @var array<int,array> */
    private array $cache = [];

    public function __construct(
        private readonly FeatureAccessService $access,
        private readonly MerchantPermissionService $perm,
    ) {
    }

    // ══════════════════════════════════════════════════════════════════
    //  الجواب الواحد
    // ══════════════════════════════════════════════════════════════════

    public const AVAILABLE = 'available';
    public const LOCKED_BY_PLAN = 'locked_by_plan';
    public const LOCKED_BY_ROLE = 'locked_by_role';
    public const LIMIT_REACHED = 'limit_reached';
    public const NOT_APPLICABLE = 'not_applicable';

    public function allows(User $user, string $code): bool
    {
        return $this->state($user, $code)['state'] === self::AVAILABLE;
    }

    /**
     * حالةُ قدرةٍ واحدة — **وبها يُبنى كلُّ شيءٍ آخر**.
     *
     * @return array{state:string,capability:array,unlock:?array,usage:?array}
     */
    public function state(User $user, string $code): array
    {
        $cap = CapabilityRegistry::find($code);

        if (! $cap) {
            // **قدرةٌ مجهولةٌ تُمنع ولا تُتجاهل**: خطأُ إملاءٍ في نداءٍ يجب
            // أن يسقط، لا أن يفتح الباب صامتاً.
            return [
                'state' => self::LOCKED_BY_PLAN,
                'capability' => ['code' => $code, 'name' => $code],
                'unlock' => null,
                'usage' => null,
                'reason' => "قدرة غير معروفة: {$code}",
            ];
        }

        $ctx = $this->context($user);

        return $this->evaluate($cap, $ctx);
    }

    /**
     * **ملفُّ خدمات التاجر** — كلُّ القدرات بحالاتها.
     *
     * وهذا ما تُرسم منه شاشةُ الحساب: **لا قائمةَ مكتوبةً في التطبيق**،
     * فقدرةٌ جديدةٌ تظهر بلا نشرةِ تطبيق.
     */
    public function manifestFor(User $user): array
    {
        $ctx = $this->context($user);

        $items = [];
        $counts = [
            self::AVAILABLE => 0, self::LOCKED_BY_PLAN => 0,
            self::LOCKED_BY_ROLE => 0, self::LIMIT_REACHED => 0,
        ];

        foreach (CapabilityRegistry::all() as $cap) {
            $row = $this->evaluate($cap, $ctx);

            // **قدرةُ قطاعٍ آخر لا تُعرض أصلاً** — ولا تُعرض مقفلةً:
            // عرضُ «مضخّات الوقود» لصيدليّةٍ يبيع ما لا يُشترى.
            if ($row['state'] === self::NOT_APPLICABLE) {
                continue;
            }

            if (isset($counts[$row['state']])) {
                $counts[$row['state']]++;
            }
            $items[] = $row;
        }

        return [
            'plan' => [
                'code' => $ctx['plan'],
                'name' => A::PLAN_LABELS[$ctx['plan']] ?? $ctx['plan'],
                'price_monthly' => A::PLAN_PRICES_SAR[$ctx['plan']] ?? 0,
                'currency' => A::PLAN_PRICE_CURRENCY,
                'expires_at' => $ctx['expires_at'],
            ],
            'business_type' => $ctx['business_type'],
            'actor' => $ctx['actor'],
            'is_owner' => $ctx['is_owner'],
            'summary' => $counts,
            'groups' => CapabilityRegistry::groups(),
            'capabilities' => $items,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  الحسم
    // ══════════════════════════════════════════════════════════════════

    private function evaluate(Capability $cap, array $ctx): array
    {
        $base = [
            'code' => $cap->code,
            'name' => $cap->name(),
            'description' => $cap->description(),
            'group' => $cap->groupName(),
            'icon' => $cap->iconName(),
            'screen' => $cap->screenRoute(),
            'is_core' => $cap->isCore(),
        ];

        $row = fn (string $state, ?array $unlock = null, ?array $usage = null): array => [
            'state' => $state, 'capability' => $base,
            'unlock' => $unlock, 'usage' => $usage,
        ];

        // ── ٠) قطاعٌ آخر ──────────────────────────────────────────────
        if (! $cap->appliesTo($ctx['business_type'])) {
            return $row(self::NOT_APPLICABLE);
        }

        // ── ①) الأساسيّة لا تُقفَل بباقةٍ ولا بأمرٍ من اللوحة ─────────
        //
        // وتبقى محكومةً بالدور: «سجلّ التدقيق» أساسيٌّ ويُكتب للجميع،
        // وقراءتُه للمصرَّح له وحده.
        if (! $cap->isCore()) {
            $planDecision = $this->planDecision($cap, $ctx);

            if ($planDecision === false) {
                return $row(self::LOCKED_BY_PLAN, $this->unlockInfo($cap, $ctx));
            }
        }

        // ── ②) الدور ──────────────────────────────────────────────────
        //
        // **والمالكُ يمرّ** — حدودُه حدودُ الباقة لا حدودُ دورِه.
        if (! $ctx['is_owner'] && ! $cap->satisfiedByPermissions($ctx['permissions'])) {
            return $row(self::LOCKED_BY_ROLE, [
                'kind' => 'role',
                'ask' => 'مالك المنشأة أو المدير',
                'permissions' => $cap->permissionPatterns(),
            ]);
        }

        // ── ③) الحدّ ──────────────────────────────────────────────────
        $usage = $this->usageFor($cap, $ctx);

        if ($usage !== null && $usage['max'] >= 0 && $usage['used'] >= $usage['max']) {
            return $row(self::LIMIT_REACHED, $this->nextPlanFor($cap, $ctx), $usage);
        }

        return $row(self::AVAILABLE, null, $usage);
    }

    /**
     * قرارُ الباقة — **والأدنى يفوز**.
     *
     * @return bool|null  `null` تعني «لم يُقرَّر» (لا صفَّ ولا حدٌّ أدنى)
     */
    private function planDecision(Capability $cap, array $ctx): ?bool
    {
        // ③ قرارُ التاجر بعينه — يعلو على الجميع
        $own = $ctx['overrides'][$cap->code] ?? null;
        if ($own !== null) {
            return $own === 'grant';
        }

        // ② قرارُ المنصّة من اللوحة
        $platform = $ctx['plan_capabilities'][$cap->code] ?? null;
        if ($platform !== null) {
            return $platform;
        }

        // ① افتراضيّ السجلّ
        $min = $cap->minimumPlan();
        if ($min === null) {
            return null;   // لا حدَّ أدنى — مفتوحة
        }

        return CapabilityRegistry::planRank($ctx['plan'])
            >= CapabilityRegistry::planRank($min);
    }

    /** **أرخصُ باقةٍ تفتحها** — ولا يُعرض الأغلى إن كفى الأرخص. */
    private function unlockInfo(Capability $cap, array $ctx): array
    {
        $needed = $cap->minimumPlan() ?? A::PLAN_STARTER;

        // إن أغلقتها اللوحةُ في باقةٍ تفوق الافتراضيّ، تُبحث أوّلُ باقةٍ
        // تفتحها فعلاً — **فالوعدُ يطابق ما ينفّذه النظام**.
        foreach (array_keys(CapabilityRegistry::PLAN_ORDER) as $plan) {
            $decision = $ctx['plan_capabilities_by_plan'][$plan][$cap->code] ?? null;

            $open = $decision !== null
                ? $decision
                : ($cap->minimumPlan() === null
                    || CapabilityRegistry::planRank($plan)
                        >= CapabilityRegistry::planRank($cap->minimumPlan()));

            if ($open) {
                $needed = $plan;
                break;
            }
        }

        return [
            'kind' => 'plan',
            'plan' => $needed,
            'plan_name' => A::PLAN_LABELS[$needed] ?? $needed,
            'price_monthly' => A::PLAN_PRICES_SAR[$needed] ?? 0,
            'price_annual' => A::PLAN_PRICES_SAR_ANNUAL[$needed] ?? 0,
            // العملةُ ترافق السعر — فلا تُكتب في شاشة. (AMIAL-CURRENCY-001)
            'currency' => A::PLAN_PRICE_CURRENCY,
        ];
    }

    private function nextPlanFor(Capability $cap, array $ctx): ?array
    {
        $current = CapabilityRegistry::planRank($ctx['plan']);

        foreach (CapabilityRegistry::PLAN_ORDER as $plan => $rank) {
            if ($rank <= $current) {
                continue;
            }
            $limits = A::PLAN_LIMITS[$plan] ?? [];
            $key = $this->limitColumn($cap->limitName());

            if ($key !== null && (($limits[$key] ?? 0) === -1
                || ($limits[$key] ?? 0) > ($ctx['limits'][$cap->limitName()] ?? 0))) {
                return [
                    'kind' => 'limit',
                    'plan' => $plan,
                    'plan_name' => A::PLAN_LABELS[$plan] ?? $plan,
                    'price_monthly' => A::PLAN_PRICES_SAR[$plan] ?? 0,
                ];
            }
        }

        return null;
    }

    /**
     * الاستهلاكُ الحاليّ مقابل الحدّ — **محسوباً من مصدره** (القاعدة ٦).
     *
     * @return array{used:int,max:int,key:string}|null
     */
    private function usageFor(Capability $cap, array $ctx): ?array
    {
        $key = $cap->limitName();
        if ($key === null) {
            return null;
        }

        $max = (int) ($ctx['limits'][$key] ?? -1);
        $ownerId = $ctx['owner_id'];

        if ($ownerId === null) {
            return null;
        }

        $used = match ($key) {
            'max_products' => \App\Models\MerchantProduct::where('merchant_user_id', $ownerId)
                ->where('is_variant_parent', false)->count(),
            'max_employees' => \App\Models\PosUser::where('merchant_user_id', $ownerId)
                ->where('is_active', true)->count(),
            'max_branches' => \App\Models\Branch::where('merchant_user_id', $ownerId)->count(),
            'max_locations' => \App\Models\Retail\MerchantLocation::where('merchant_user_id', $ownerId)
                ->where('is_active', true)->count(),
            'max_pos_devices' => \App\Models\PosUser::where('merchant_user_id', $ownerId)
                ->where('is_active', true)->count(),
            default => null,
        };

        if ($used === null) {
            return null;
        }

        return ['used' => $used, 'max' => $max, 'key' => $key];
    }

    private function limitColumn(?string $limitName): ?string
    {
        return match ($limitName) {
            'max_products' => 'products',
            'max_employees' => 'employees',
            'max_branches' => 'branches',
            'max_pos_devices' => 'pos_devices',
            // **المواقعُ تتبع الفروع** — ومستودعٌ بلا سقفٍ يلتفّ على حدّ الفروع.
            'max_locations' => 'branches',
            default => null,
        };
    }

    // ══════════════════════════════════════════════════════════════════
    //  السياق — يُبنى مرّةً لكلّ حساب في الطلب الواحد
    // ══════════════════════════════════════════════════════════════════

    private function context(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        $access = $this->access->accessFor($user);

        // **أسماءُ المفاتيح تُقرأ من المصدر لا تُفترَض** — قِيس أنّ
        // `accessFor` يردّ `subscription_plan` و`merchant_user_id`، لا
        // `plan` و`owner_id`. والافتراضُ كان يُرجع الجميعَ إلى المجّاني
        // صامتاً: كلُّ قدرةٍ مقفلةٌ، ولا خطأ في أيّ سجلّ.
        $plan = $access['subscription_plan'] ?? A::PLAN_FREE;
        $ownerId = $access['merchant_user_id'] ?? null;

        // **مالكٌ أم موظّف** — والفرقُ يقرّر أيُفحص الدورُ أصلاً.
        $isOwner = ($access['actor'] ?? 'customer') === 'owner';

        $permissions = [];
        if (! $isOwner) {
            try {
                $permissions = $this->perm->effective($user);
                // من له صلاحيّاتُ مالكٍ في محرّك الأدوار يُعامَل مالكاً.
                $isOwner = $isOwner || $this->perm->isOwner($user);
            } catch (\Throwable) {
                // **لا يُفتح البابُ عند عطل**: تبقى القائمةُ فارغةً فيُقرأ
                // «لا صلاحية»، وهو الجانب الآمن.
                $permissions = [];
            }
        }

        $byPlan = PlanCapability::allDecisions();

        $ctx = [
            'plan' => $plan,
            'expires_at' => $access['subscription_expires_at'] ?? null,
            'business_type' => $access['business_type'] ?? null,
            'actor' => $access['actor'] ?? 'customer',
            'is_owner' => $isOwner,
            'owner_id' => $ownerId,
            'permissions' => $permissions,
            'limits' => $access['limits'] ?? [],
            'plan_capabilities' => $byPlan[$plan] ?? [],
            'plan_capabilities_by_plan' => $byPlan,
            'overrides' => $ownerId
                ? MerchantCapabilityOverride::activeFor($ownerId) : [],
        ];

        return $this->cache[$user->id] = $ctx;
    }

    /** يُفرَّغ بعد تعديلِ باقةٍ أو منحة — وإلّا قرأ الطلبُ حالةً قديمة. */
    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
            PlanCapability::flush();

            return;
        }

        unset($this->cache[$userId]);
        PlanCapability::flush();
    }
}
