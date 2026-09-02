<?php

namespace App\Support\Access;

/**
 * AMIAL-ENTITLEMENTS-001 — **القدرةُ كيانٌ لا كلمة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع:** كانت الميزةُ سلسلةَ نصٍّ تظهر في أربعة مواضعَ لا
 * يعرف بعضُها بعضاً:
 *
 * ```
 * AccessConstants::F_INVENTORY = 'inventory'   ← ثابت
 * AccessPresets::planFeatures()                ← أيّ باقةٍ تفتحها
 * if (!hasFeature(...)) في متحكّم               ← ١٧ موضعاً
 * AccessGate(feature: 'inventory') في Dart      ← ٢٦ موضعاً
 * ```
 *
 * **فأربعةٌ وأربعون موضعاً تحمل الحقيقةَ ولا موضعَ يملكها.** ولا أحدَ
 * منها يعرف: ما اسمُها بالعربيّة؟ بكم تُفتح؟ أيّ مساراتٍ تحرس؟ أيّ شاشةٍ
 * تفتح؟ أيّ صلاحيّةٍ تحتاج؟ أيّ حدٍّ يقيّدها؟
 *
 * فصارت القدرةُ **صفّاً واحداً** يحمل ذلك كلَّه، ويُشتقّ منه:
 * ملفُّ خدمات التاجر · حراسةُ الخادم · شاشةُ الحساب · مصفوفةُ الإدارة ·
 * صفحةُ التسعير · الحرّاس.
 *
 * **وإضافةُ قدرةٍ غداً سطرٌ واحد** — لا هجرةَ ولا `if` ولا بطاقةٌ في Dart.
 */
final class Capability
{
    private string $nameAr = '';
    private string $descAr = '';
    private string $group = 'عامّ';
    private string $icon = 'widgets';

    /** أدنى باقةٍ تفتحها — و`null` تعني «للجميع». */
    private ?string $minPlan = null;

    /** **قدرةٌ أساسيّةٌ لا تُباع** — انظر `core()`. */
    private bool $isCore = false;

    /** أصنافُ النشاط التي تنطبق عليها — وفارغةٌ تعني «كلَّها». */
    private array $businessTypes = [];

    /** أنماطُ صلاحيّاتٍ يحتاج **الموظّف** واحدةً منها على الأقلّ. */
    private array $permissions = [];

    /** بوادئُ مساراتٍ تحرسها هذه القدرة (بلا `api/v1/amial/merchant/`). */
    private array $routes = [];

    /** مفتاحُ الحدّ في `PLAN_LIMITS` إن كان لها حدُّ كمّيّة. */
    private ?string $limitKey = null;

    /** مسارُ الشاشة في التطبيق — **وبلا شاشةٍ لا تظهر في ملفّ الخدمات**. */
    private ?string $screen = null;

    /**
     * **حالةُ البناء — و`available` تعني «مبنيّةٌ ويُوصَل إليها».**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمنُ الذي أدخل هذا الحقل:** أربعُ قدراتٍ كانت **تُباع في
     * الباقات** ولا نقطةَ نهايةٍ لها ولا فعلَ في التطبيق:
     * `offline_pos` و`retail.discount_limit` — تظهران في صفحة التسعير
     * وفي «قدراتي» كأنّهما جاهزتان، **ويدفع التاجرُ ثمنَهما**.
     *
     * وهذا أسوأُ من ثغرةٍ أمنيّة: الثغرةُ تُعطي بلا ثمن، وهذا **يأخذ
     * الثمنَ بلا عطاء**.
     *
     * فصارت الحالةُ حقلاً صريحاً:
     *
     *   `available`    مبنيّةٌ وموصولة — تُباع
     *   `coming_soon`  مُعلَنةٌ ولم تُبنَ — **لا تُمنح ولا تُباع**، وتُعرض «قريباً»
     *
     * و**`coming_soon` تُنزع من `planFeatures` أيضاً** — فحقلٌ يقول
     * «قريباً» بينما الاتّحادُ يمنحها يُنتج زرّاً يعمل على لا شيء.
     */
    private string $status = 'available';

    private function __construct(public readonly string $code) {}

    public static function make(string $code): self
    {
        return new self($code);
    }

    public function nameAr(string $v): self { $this->nameAr = $v; return $this; }
    public function descAr(string $v): self { $this->descAr = $v; return $this; }
    public function group(string $v): self { $this->group = $v; return $this; }
    public function icon(string $v): self { $this->icon = $v; return $this; }
    public function minPlan(?string $v): self { $this->minPlan = $v; return $this; }
    /**
     * يضيّق نطاق القدرة عند استدعائه أكثر من مرة، ولا يوسّعه عرضاً.
     *
     * في سجل القدرات قد تُكتب قاعدة عامة ثم قاعدة قطاعية أدق في السلسلة
     * نفسها. الاستبدال كان يجعل الاستدعاء الأخير يفتح القدرة لقطاعات لا
     * تملك مساراتها (مثل مركز التجزئة داخل الصيدلية). تقاطع النطاقين
     * يحافظ على القيد الأدق ويجعل تكرار السلسلة آمناً.
     */
    public function businessTypes(array $v): self
    {
        $v = array_values(array_unique($v));
        $this->businessTypes = $this->businessTypes === []
            ? $v
            : array_values(array_intersect($this->businessTypes, $v));

        return $this;
    }
    public function permissions(array $v): self { $this->permissions = $v; return $this; }
    public function routes(array $v): self { $this->routes = $v; return $this; }
    public function limit(?string $v): self { $this->limitKey = $v; return $this; }
    public function screen(?string $v): self { $this->screen = $v; return $this; }

    /**
     * **قدرةٌ أساسيّةٌ لا تُباع، ولا تُقفَل بباقةٍ ولا بأمرٍ من اللوحة.**
     *
     * تجميدُ التكلفة، وبقاءُ المخزون السالب ظاهراً، ومراحلُ التحويل،
     * والحجزُ حتّى نجاح الدفع — **هذه تمنع أرقاماً كاذبة، لا تُضيف قيمة**.
     * وبيعُها بالباقة بيعُ أرقامٍ خاطئةٍ لمن دفع أقلّ.
     */
    public function core(): self
    {
        $this->isCore = true;
        $this->minPlan = null;

        return $this;
    }

    // ── قراءات ────────────────────────────────────────────────────────

    public function name(): string { return $this->nameAr ?: $this->code; }
    public function description(): string { return $this->descAr; }
    public function groupName(): string { return $this->group; }
    public function iconName(): string { return $this->icon; }
    public function minimumPlan(): ?string { return $this->minPlan; }
    public function isCore(): bool { return $this->isCore; }
    public function permissionPatterns(): array { return $this->permissions; }
    public function routePrefixes(): array { return $this->routes; }
    public function limitName(): ?string { return $this->limitKey; }
    public function screenRoute(): ?string { return $this->screen; }
    public function appliesToAllBusinessTypes(): bool { return $this->businessTypes === []; }

    public function appliesTo(?string $businessType): bool
    {
        if ($this->businessTypes === []) {
            return true;
        }

        return $businessType !== null && in_array($businessType, $this->businessTypes, true);
    }

    /** أيحرس هذا المسارَ؟ — يُقارَن بالبادئة بعد تطبيع الشرطات. */
    public function guardsRoute(string $uri): bool
    {
        $uri = trim($uri, '/');

        foreach ($this->routes as $prefix) {
            $prefix = trim($prefix, '/');
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * أتكفي صلاحيّاتُ الموظّف؟
     *
     * **والنمطُ يقبل `*`** — فـ`retail.transfer.*` تعني أيَّ فعلٍ في
     * التحويلات. ومن لم تُذكر له صلاحيّةٌ لا يُقيَّد بالدور (القدرةُ
     * للمنشأة لا للموظّف).
     */
    public function satisfiedByPermissions(array $held): bool
    {
        if ($this->permissions === []) {
            return true;
        }

        foreach ($this->permissions as $pattern) {
            if (! str_contains($pattern, '*')) {
                if (in_array($pattern, $held, true)) {
                    return true;
                }

                continue;
            }

            $prefix = rtrim(substr($pattern, 0, strpos($pattern, '*')), '.');
            foreach ($held as $p) {
                if ($p === $prefix || str_starts_with($p, $prefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** **قدرةٌ مُعلَنةٌ لم تُبنَ بعد** — لا تُمنح، وتُعرض «قريباً». */
    public function comingSoon(): self
    {
        $this->status = 'coming_soon';

        return $this;
    }

    public function isComingSoon(): bool
    {
        return $this->status === 'coming_soon';
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'code' => $this->code,
            'name' => $this->name(),
            'description' => $this->descAr,
            'group' => $this->group,
            'icon' => $this->icon,
            'min_plan' => $this->minPlan,
            'is_core' => $this->isCore,
            'business_types' => $this->businessTypes,
            'permissions' => $this->permissions,
            'routes' => $this->routes,
            'limit' => $this->limitKey,
            'screen' => $this->screen,
        ];
    }
}
