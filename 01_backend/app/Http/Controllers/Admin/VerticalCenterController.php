<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Verticals\VerticalRegistry;
use App\Http\Controllers\Controller;
use App\Models\Access\MerchantVerticalDefinition;
use App\Models\MerchantProfile;
use App\Services\AuditService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-VERTICAL-COMPOSE-001 — **مركزُ القطاعات: قطاعٌ يُنشَأ بلا نشرة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال:** «ماذا لو أردتُ إضافةَ قطاعٍ جديد؟ يبدو ذلك مستحيلاً —
 * يحتاج ترميزاً، بينما البنيةُ التحتيّةُ موجودة».
 *
 * **والجوابُ مقيسٌ:** `quick_sale` قطاعٌ عاملٌ في المنتج **بصفر شاشةٍ
 * خاصّةٍ به** — لا مجلّدَ له في `lib/features/` إطلاقاً. فهو تركيبٌ من
 * الكاشير المشترك، ودليلٌ حيٌّ أنّ قطاعاً كاملاً يقوم من قدراتٍ مبنيّة.
 *
 * فما يُنشَأ من هنا **قطاعٌ مركَّب**: اسمٌ وأيقونةٌ ونواةٌ من القدرات
 * المشتركة وعمقٌ لكلّ باقةٍ وشاشةُ بداية. ويصل التاجرَ في نفس اللحظة
 * — لا بناءَ تطبيقٍ ولا نشرةَ متجر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثلاثةُ حدودٍ تُقال هنا ولا تُترك للاكتشاف:**
 *
 * ① **لا يُنشَأ ولا يُحذَف قطاعٌ من الستّة المبنيّة.** تلك تبيع اليومَ
 *   لتجّارٍ قائمين، وتعديلُ قوائمها من لوحةٍ يُغيّر ما يرونه بلا مراجعة.
 * ② **القدراتُ المعروضةُ للتركيب هي العامّةُ وعائلةُ التجارة وحدَها.**
 *   ومحرّكُ قطاعٍ خاصّ (دفعاتُ الصيدليّة · مضخّاتُ الوقود · مطبخُ
 *   المطعم) لا يُمنَح: شاشتُه تقرأ جداولَ ذلك القطاع، فتظهر في مخبزٍ
 *   بأرقامٍ صفريّةٍ تُقرأ «فحصنا فلم نجد» وهي غياب.
 * ③ **قطاعٌ يستعمله تجّارٌ لا يُحذَف** — يُعطَّل. والحذفُ يترك
 *   `business_type` في ملفّاتهم يشير إلى لا شيء، فيسقطون إلى «بلا نشاط»
 *   ويُطالَبون باختيارٍ جديدٍ في أوّل دخول.
 *
 * يظهر في : لوحة الإدارة ← الباقات والاشتراكات ← 🏗️ قطاعات التجّار
 * ويُوصل إليه من : القائمة الجانبيّة (بجانب «الباقات والقدرات»).
 */
class VerticalCenterController extends Controller
{
    public function page()
    {
        return view('admin-views.amial.verticals.index');
    }

    private function ok(array $data, string $message = ''): JsonResponse
    {
        return response()->json(['ok' => true, 'message' => $message] + $data);
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message], $code);
    }

    // ══════════════════════════════════════════════════════════════════
    //  القراءة
    // ══════════════════════════════════════════════════════════════════

    /** كلُّ القطاعات: الستّةُ المبنيّةُ للاطّلاع، والمُضافةُ للتحرير. */
    public function index(): JsonResponse
    {
        VerticalRegistry::flush();

        $counts = MerchantProfile::query()
            ->selectRaw('business_type, COUNT(*) AS c')
            ->groupBy('business_type')->pluck('c', 'business_type')->all();

        $rows = [];

        foreach (VerticalRegistry::current() as $code => $vertical) {
            $isBuiltIn = ! VerticalRegistry::isAdminDefined($code);
            $row = $isBuiltIn ? null : MerchantVerticalDefinition::where('code', $code)->first();

            $rows[] = [
                'code' => $code,
                'name' => $vertical->nameAr(),
                'label' => VerticalRegistry::labels()[$code] ?? $vertical->nameAr(),
                'hint' => $row?->hint_ar,
                'icon' => $row?->icon,
                'color' => $row?->color,
                'is_built_in' => $isBuiltIn,
                'is_active' => $isBuiltIn ? true : (bool) $row?->is_active,
                'sort_order' => $isBuiltIn ? 0 : (int) ($row?->sort_order ?? 100),
                'home_capability' => $vertical->homeCapability(),
                'core_features' => $vertical->own(),
                'paid_depth' => $vertical->paidDepth(),
                // **ولا يُعرَض عددٌ بلا مصدره**: هذا عددُ ملفّات التجّار
                // التي `business_type` فيها هذا الرمز — وهو ما يمنع الحذف.
                'merchants' => (int) ($counts[$code] ?? 0),
            ];
        }

        return $this->ok([
            'verticals' => $rows,
            'shared' => \App\Domain\Verticals\MerchantVertical::shared(),
            'plans' => array_map(fn ($p) => [
                'code' => $p, 'label' => A::PLAN_LABELS[$p] ?? $p,
            ], A::ALL_PLANS),
            'capabilities' => $this->composableCatalog(),
        ]);
    }

    /**
     * القدراتُ المعروضةُ للتركيب — **ومعها ما تفتحه من شاشة**.
     *
     * @return array<int,array<string,mixed>>
     */
    private function composableCatalog(): array
    {
        $shared = \App\Domain\Verticals\MerchantVertical::shared();
        $out = [];

        foreach (CapabilityRegistry::composable() as $code => $cap) {
            $out[] = [
                'code' => $code,
                'name' => $cap->name(),
                'group' => $cap->groupName(),
                'min_plan' => $cap->minimumPlan(),
                'is_core' => $cap->isCore(),
                'has_screen' => $cap->screenRoute() !== null,
                // **ما يناله كلُّ تاجرٍ أصلاً لا يُختار** — واختيارُه يوهم
                // أنّه قرارٌ وهو ممنوحٌ من الصندوق المشترك على كلّ حال.
                'is_shared' => in_array($code, $shared, true),
            ];
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    //  الكتابة
    // ══════════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        $code = strtolower(trim((string) $request->input('code')));

        if (! preg_match('/^[a-z][a-z0-9_]{2,39}$/', $code)) {
            return $this->fail('الرمز: حروفٌ إنجليزيّةٌ صغيرةٌ وأرقامٌ وشرطةٌ سفليّة، ٣ إلى ٤٠ محرفاً، ويبدأ بحرف');
        }

        // ① المبنيُّ لا يُستبدَل — وهو حزامٌ ثانٍ فوق ما يفعله السجلّ.
        if (in_array($code, A::ALL_BUSINESS_TYPES, true)) {
            return $this->fail('«' . $code . '» قطاعٌ مبنيٌّ في المنصّة — يُقرأ ولا يُنشَأ من هنا');
        }

        if (MerchantVerticalDefinition::where('code', $code)->exists()) {
            return $this->fail('هذا الرمز مستعمَل بالفعل');
        }

        $error = null;
        $payload = $this->payload($request, $error);

        if ($error !== null) {
            return $this->fail($error);
        }

        $row = MerchantVerticalDefinition::create($payload + [
            'code' => $code,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        VerticalRegistry::flush();

        $this->audit($request, 'ADMIN_VERTICAL_CREATE', 'CREATED', $code, [
            'name' => $row->name_ar,
            'core_features' => $row->core_features,
            'paid_depth' => $row->paid_depth,
        ]);

        return $this->ok(['code' => $code], 'أُنشئ القطاع — ويظهر للتجّار في قائمة اختيار النشاط فوراً');
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $row = MerchantVerticalDefinition::where('code', $code)->first();

        if (! $row) {
            return $this->fail(in_array($code, A::ALL_BUSINESS_TYPES, true)
                ? 'قطاعٌ مبنيٌّ في المنصّة — لا يُعدَّل من هنا'
                : 'لا قطاعَ بهذا الرمز', 404);
        }

        $error = null;
        $payload = $this->payload($request, $error);

        if ($error !== null) {
            return $this->fail($error);
        }

        $before = ['core_features' => $row->core_features, 'paid_depth' => $row->paid_depth];

        $row->fill($payload + ['updated_by' => $request->user()?->id])->save();

        VerticalRegistry::flush();

        $this->audit($request, 'ADMIN_VERTICAL_UPDATE', 'UPDATED', $code, [
            'before' => $before,
            'after' => ['core_features' => $row->core_features, 'paid_depth' => $row->paid_depth],
        ]);

        return $this->ok([], 'حُفظ — ويصل التجّارَ عند أوّل تحديثٍ لصلاحيّاتهم');
    }

    /**
     * **الحذفُ يُمنَع على المستعمَل ويُقترَح التعطيلُ بدلَه.**
     *
     * وحذفُ قطاعٍ يستعمله تاجرٌ لا يُسقط شيئاً في الحال: `business_type`
     * يبقى نصّاً في ملفّه، فيردّ السجلُّ `null` وتُمنَح القائمةُ الفارغة
     * — **متجرٌ يعمل أمسِ ولا يفتح شاشةً اليوم، بلا خطأٍ في أيّ سجلّ.**
     */
    public function destroy(Request $request, string $code): JsonResponse
    {
        $row = MerchantVerticalDefinition::where('code', $code)->first();

        if (! $row) {
            return $this->fail('لا قطاعَ بهذا الرمز', 404);
        }

        $users = MerchantProfile::where('business_type', $code)->count();

        if ($users > 0) {
            return $this->fail(
                "لا يُحذَف: {$users} تاجراً مسجَّلاً في هذا القطاع — "
                . 'عطّله بدلَ ذلك، فيختفي من قائمة الاختيار ويبقى عاملاً لمن فيه');
        }

        $row->delete();
        VerticalRegistry::flush();

        $this->audit($request, 'ADMIN_VERTICAL_DELETE', 'DELETED', $code, []);

        return $this->ok([], 'حُذف القطاع');
    }

    /**
     * تعطيلٌ وتشغيل — **والمعطَّلُ يختفي من الاختيار ويبقى عاملاً لمن فيه.**
     *
     * وهذا مقصود: التعطيلُ قرارُ «لا تُسجّل أحداً جديداً»، لا قرارُ إغلاق
     * متاجرَ تعمل. وإغلاقُها يُفعَل بنقل تجّارها إلى قطاعٍ آخر.
     */
    public function toggle(Request $request, string $code): JsonResponse
    {
        $row = MerchantVerticalDefinition::where('code', $code)->first();

        if (! $row) {
            return $this->fail('لا قطاعَ بهذا الرمز', 404);
        }

        $row->is_active = ! $row->is_active;
        $row->updated_by = $request->user()?->id;
        $row->save();

        VerticalRegistry::flush();

        $this->audit($request, 'ADMIN_VERTICAL_TOGGLE',
            $row->is_active ? 'ENABLED' : 'DISABLED', $code, []);

        return $this->ok(
            ['is_active' => (bool) $row->is_active],
            $row->is_active
                ? 'شُغّل — ويظهر في قائمة اختيار النشاط'
                : 'عُطّل — لا يظهر لمن يسجّل الآن، ويبقى عاملاً لتجّاره الحاليّين');
    }

    // ══════════════════════════════════════════════════════════════════

    /**
     * قراءةُ الحقول المشتركة بين الإنشاء والتعديل، والتحقّقُ منها.
     *
     * @param  string|null  $error  يُملأ بسبب الرفض إن وقع
     * @return array<string,mixed>
     */
    private function payload(Request $request, ?string &$error): array
    {
        $name = trim((string) $request->input('name_ar'));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $error = 'الاسم: من حرفين إلى ٦٠ حرفاً';

            return [];
        }

        $allowed = array_keys(CapabilityRegistry::composable());

        $core = $this->cleanList($request->input('core_features'), $allowed, $unknownCore);

        if ($unknownCore !== []) {
            $error = 'قدرةٌ لا تُركَّب في قطاعٍ جديد: ' . implode('، ', $unknownCore);

            return [];
        }

        if ($core === []) {
            $error = 'اختر قدرةً واحدةً على الأقلّ — قطاعٌ بلا نواةٍ يفتح للتاجر ما يفتحه لكلّ تاجر، فلا معنى له';

            return [];
        }

        $depth = [];

        foreach ((array) $request->input('paid_depth', []) as $plan => $features) {
            $plan = (string) $plan;

            if (! in_array($plan, A::ALL_PLANS, true) || $plan === A::PLAN_FREE) {
                // **والمجّانيّةُ لا عمقَ لها** — العمقُ هو ما يُباع.
                continue;
            }

            $list = $this->cleanList($features, $allowed, $unknownDepth);

            if ($unknownDepth !== []) {
                $error = 'قدرةٌ لا تُركَّب في قطاعٍ جديد: ' . implode('، ', $unknownDepth);

                return [];
            }

            // ما هو في النواة لا يُباع فوقها — منحُه مرّتين يجعل نزعَه من
            // أحد الموضعين بلا أثر، وهو بعينه ما وُلد منه `shared()`.
            $list = array_values(array_diff($list, $core));

            if ($list !== []) {
                $depth[$plan] = $list;
            }
        }

        $granted = $core;

        foreach ($depth as $list) {
            $granted = array_merge($granted, $list);
        }

        $home = trim((string) $request->input('home_capability'));

        if ($home !== '' && ! in_array($home, $granted, true)) {
            $error = 'شاشةُ البداية يجب أن تكون من قدرات هذا القطاع';

            return [];
        }

        return [
            'name_ar' => $name,
            'hint_ar' => $this->orNull($request->input('hint_ar'), 120),
            'icon' => $this->orNull($request->input('icon'), 40),
            'color' => $this->orNull($request->input('color'), 9),
            'core_features' => $core,
            'paid_depth' => $depth,
            'home_capability' => $home !== '' ? $home : null,
            'is_active' => (bool) $request->boolean('is_active', true),
            'sort_order' => max(0, min(999, (int) $request->input('sort_order', 100))),
        ];
    }

    /**
     * @param  array<int,string>  $allowed
     * @param  array<int,string>|null  $unknown  يُملأ بما رُفض
     * @return array<int,string>
     */
    private function cleanList(mixed $raw, array $allowed, ?array &$unknown = null): array
    {
        $unknown = [];
        $out = [];

        foreach ((array) $raw as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = trim($code);

            if ($code === '') {
                continue;
            }

            if (in_array($code, $allowed, true)) {
                $out[] = $code;
            } else {
                // **ولا يُبتلَع الرفضُ صامتاً** — من اختار قدرةً ثمّ لم
                // يجدها بعد الحفظ يظنّ أنّه حفظها.
                $unknown[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    private function orNull(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private function audit(Request $request, string $action, string $decision, string $code, array $context): void
    {
        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'merchant_vertical',
            'subject_id' => $code,
            'action' => $action,
            'decision_code' => $decision,
            'reason' => trim((string) $request->input('reason', '')) ?: null,
            'severity' => 'warning',
            'context' => $context,
        ]);
    }
}
