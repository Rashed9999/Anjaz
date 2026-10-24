<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeeChangeLog;
use App\Models\FeeScheme;
use App\Models\User;
use App\Services\FeeDiscountPolicy;
use App\Services\FeeProfitReportService;
use App\Services\FeeService;
use App\Services\MoneyService;
use App\Services\ZonePolicyService;
use App\Support\Fees\FeeOperationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * AMIAL-FEE-ENGINE-001 · AMIAL-FEE-TRUTH-016 — **مركزُ الرسوم والأرباح.**
 *
 * ══════════════════════════════════════════════════════════════════════
 *   GET  /admin/amial/fees                 — نظرةٌ عامّة: صحّةُ المحرّك + الخطط
 *   GET  /admin/amial/fees/operations      — سجلُّ العمليّات المسعَّرة
 *   GET  /admin/amial/fees/policies        — الخصوماتُ والسياسات
 *   GET  /admin/amial/fees/profit          — الأرباح
 *   GET  /admin/amial/fees/drill           — العمليّاتُ خلف رقمِ الأرباح
 *   GET  /admin/amial/fees/history/{code?} — سجلُّ التغييرات
 *   GET  /admin/amial/fees/create          — نسخةٌ جديدة
 *   POST /admin/amial/fees                 — حفظ
 *   POST /admin/amial/fees/simulate        — محاكٍ حيّ (JSON)
 *   POST /admin/amial/fees/{id}/deactivate — تعطيل
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقراءةُ مفصولةٌ عن الكتابة** في `routes/admin/amial.php`:
 * `platform.fees.view` لشاشات القراءة، و`platform.fees.update` للكتابة.
 * فتقريرُ الأرباح لا يغيّر شيئاً، ووضعُه خلف إذن التعديل يُغري بمنح إذن
 * **تغيير المال كلِّه** لمن يحتاج تقريراً. (`amial-rbac`: أقلُّ صلاحيّةٍ تكفي.)
 */
class FeeSchemeController extends Controller
{
    public function __construct(
        private readonly FeeService $feeService,
        private readonly FeeProfitReportService $profit,
    ) {}

    /**
     * **التبويبات** — مركزٌ واحدٌ لا خمسُ شاشاتٍ متفرّقة.
     *
     * ══════════════════════════════════════════════════════════════════
     * كانت شاشةُ الرسوم وشاشةُ الأرباح وشاشةُ `charge-setup` القديمة ثلاثةَ
     * أبوابٍ لا يقود أحدُها إلى الآخر، فمن دخل واحداً لا يعلم بالثانية.
     * (`amial-navigation` — والقاعدةُ الثانيةَ عشرةَ: صفحةٌ لا يُوصل إليها
     * ليست مبنيّة.)
     *
     * @return array<int,array<string,mixed>>
     */
    private function tabs(string $active): array
    {
        $t = [
            ['key' => 'overview', 'label' => 'نظرة عامّة', 'icon' => 'tio-dashboard',
                'url' => route('admin.amial.fees.index')],
            ['key' => 'operations', 'label' => 'سجلّ العمليّات', 'icon' => 'tio-list-numbered',
                'url' => route('admin.amial.fees.operations')],
            ['key' => 'policies', 'label' => 'الخصومات والسياسات', 'icon' => 'tio-gift',
                'url' => route('admin.amial.fees.policies')],
            ['key' => 'profit', 'label' => 'الأرباح', 'icon' => 'tio-chart-bar-4',
                'url' => route('admin.amial.fees.profit')],
            ['key' => 'history', 'label' => 'سجلّ التغييرات', 'icon' => 'tio-history',
                'url' => route('admin.amial.fees.history')],
        ];

        foreach ($t as &$row) {
            $row['active'] = $row['key'] === $active;
        }

        return $t;
    }

    /** المناطقُ من مصدرها الحقيقيّ — لا قائمةٌ مكتوبةٌ في قالب. */
    private function zones(): array
    {
        $out = [];

        foreach (ZonePolicyService::VALID_ZONES as $z) {
            $out[$z] = ZonePolicyService::zoneNameAr($z).' — '.$z;
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    // ① نظرةٌ عامّة — صحّةُ المحرّك قبل الجدول
    // ══════════════════════════════════════════════════════════════════

    public function webIndex(Request $request)
    {
        $zone = $this->safeZone($request->query('zone'));
        $search = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');

        // ══════════════════════════════════════════════════════════════
        // AMIAL-FEE-PLAN-001 — **مفتاحٌ لا يعرف الباقةَ يبتلع نسخةً.**
        //
        // كان `keyBy(code|actor)` — ومع بُعد الباقة صار لِـ(‏رمزٍ × جهة)
        // أكثرُ من نسخةٍ نشطة. و`keyBy` **يُبقي الأخيرةَ ويرمي ما قبلها
        // صامتاً**: فتُضبط نسخةٌ لـ«البداية» فتختفي العامّةُ من الشاشة،
        // أو العكس — **والمحرّكُ يُطبّق الاثنتين**.
        //
        // فتُجمَع بالباقة، ويُعرَض لكلِّ سطرٍ **كم نسخةً تحته**.
        // ══════════════════════════════════════════════════════════════
        $activeRows = FeeScheme::query()
            ->where('zone_code', $zone)
            ->where('is_active', true)
            ->orderByRaw('plan IS NULL')   // المخصَّصُ أوّلاً — كما يقرؤه المحرّك
            ->orderBy('code')
            ->get()
            ->groupBy(fn ($s) => $s->code.'|'.$s->applies_to);

        // النسخةُ التي تُمثّل السطر: العامّةُ إن وُجدت، وإلّا أوّلُ مخصَّصة.
        $active = $activeRows->map(
            fn ($g) => $g->firstWhere('plan', null) ?? $g->first());

        // ══════════════════════════════════════════════════════════════
        // **صحّةُ المحرّك — الصفُّ الأوّلُ في الشاشة.**
        //
        // فجدولُ نسخٍ نشطةٍ يقول ما **ضُبط**، ولا يقول ما **لم يُضبط**.
        // والعطلُ الذي جعل عمليّاتِ الوكيل مجّانيّةً شهوراً كان في الفراغ
        // لا في الصفوف: لا صفَّ يُرى فلا شيءَ يُنبّه. (‏القاعدة السابعة:
        // «غير معروف» ليس صفراً.)
        //
        // فالجدولُ يُبنى من **سجلّ العمليّات** لا من صفوف القاعدة، وكلُّ
        // تركيبةٍ (‏عمليّة × جهة) لها سطرٌ ولو لم تُسعَّر بعد.
        $rows = [];
        $health = ['priced' => 0, 'zero' => 0, 'missing' => 0, 'not_wired' => 0];

        foreach (FeeOperationRegistry::all() as $code => $op) {
            $matches = $category === '' || $op->category === $category;

            if ($matches && $search !== '') {
                $matches = str_contains($op->labelAr, $search)
                    || str_contains(strtoupper($code), strtoupper($search));
            }

            foreach ($op->actors as $actor) {
                $scheme = $active[$code.'|'.$actor] ?? null;

                $state = match (true) {
                    ! $op->isLive() => 'not_wired',
                    $scheme === null => 'missing',
                    MoneyService::gt((string) $scheme->percent_rate, '0')
                        || MoneyService::gt((string) $scheme->fixed_amount, '0') => 'priced',
                    default => 'zero',
                };

                // **الصحّةُ تُحسب على الكلّ لا على المرشَّح** — وإلّا صار
                // البحثُ يغيّر عدّادَ الأعطال، فيُخفيها من بحث عنه بكلمة.
                $health[$state]++;

                if ($matches) {
                    $group = $activeRows[$code.'|'.$actor] ?? collect();

                    $rows[] = ['op' => $op, 'actor' => $actor,
                        'scheme' => $scheme, 'state' => $state,
                        // **وعددُ نسخ الباقات يُقال** — فسطرٌ يعرض سعراً
                        // واحداً وتحته ثلاثةٌ يُقرأ أنّ السعرَ واحدٌ للجميع.
                        'plan_overrides' => $group->filter(
                            fn ($x) => $x->plan !== null)->pluck('plan')->all()];
                }
            }
        }

        return view('admin-views.amial.fees.index', [
            'tabs' => $this->tabs('overview'),
            'rows' => $rows,
            'health' => $health,
            'zone' => $zone,
            'zones' => $this->zones(),
            'search' => $search,
            'category' => $category,
            'categories' => FeeOperationRegistry::CATEGORIES,
            'canWrite' => $this->canWrite($request),
            'lifetime' => $this->profit->lifetimeNet(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ② سجلُّ العمليّات — ما يُسعَّر، ومن يستهلكه
    // ══════════════════════════════════════════════════════════════════

    public function webOperations(Request $request)
    {
        return view('admin-views.amial.fees.operations', [
            'tabs' => $this->tabs('operations'),
            'grouped' => FeeOperationRegistry::grouped(),
            'categories' => FeeOperationRegistry::CATEGORIES,
            'canWrite' => $this->canWrite($request),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ③ الخصوماتُ والسياسات
    // ══════════════════════════════════════════════════════════════════

    /**
     * **الخصمُ سياسةٌ فوق الرسم** — ويُرى حيث تُرى الرسوم.
     *
     * فكان مبعثراً في `business-settings/charge-setup`، ومن يضبط رسمَ
     * التحويل لا يعلم أنّ خصماً بنسبةٍ يعمل فوقه فيرى محصَّلاً أقلَّ ممّا
     * سعّر ولا يجد سببَه.
     */
    public function webPolicies(Request $request)
    {
        $policy = app(FeeDiscountPolicy::class);

        return view('admin-views.amial.fees.policies', [
            'tabs' => $this->tabs('policies'),
            'enabled' => $policy->enabled(),
            'sendPercent' => $policy->discountPercentFor('SEND_MONEY'),
            'cashOutPercent' => $policy->discountPercentFor('CASH_OUT'),
            'limit' => $policy->limit(),
            'canWrite' => $this->canWrite($request),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ④ الأرباح
    // ══════════════════════════════════════════════════════════════════

    public function webProfit(Request $request)
    {
        [$from, $to] = $this->period($request);

        return view('admin-views.amial.fees.profit', [
            'tabs' => $this->tabs('profit'),
            'report' => $this->profit->forPeriod($from, $to),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * **§19 — التنقّلُ إلى الأصل.**
     *
     * `الإجمالي ← العمليّات ← لقطةُ الرسم ← الدفتر ← التدقيق`
     *
     * فرقمٌ ماليٌّ لا يُنقَر إليه رقمٌ لا يُفسَّر — و`amial-admin-command`
     * تقولها نصّاً: «Never display an unexplained financial number».
     */
    public function webDrill(Request $request)
    {
        [$from, $to] = $this->period($request);
        $type = trim((string) $request->query('type', ''));

        return view('admin-views.amial.fees.drill', [
            'tabs' => $this->tabs('profit'),
            'transactions' => $this->profit->transactionsBehind($from, $to, $type ?: null),
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'typeLabel' => $type !== ''
                ? FeeProfitReportService::typeLabel($type) : 'كلّ الأنواع',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // ⑤ سجلُّ التغييرات
    // ══════════════════════════════════════════════════════════════════

    public function webHistory(Request $request, ?string $code = null)
    {
        if ($code !== null && FeeOperationRegistry::find($code) === null) {
            abort(404);
        }

        $versions = $code === null
            ? null
            : FeeScheme::query()
                ->where('code', $code)
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString();

        $logs = FeeChangeLog::query()
            ->when($code !== null, fn ($q) => $q->where('code', $code))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('admin-views.amial.fees.history', [
            'tabs' => $this->tabs('history'),
            'code' => $code,
            'label' => $code !== null ? FeeOperationRegistry::label($code) : null,
            'operations' => FeeOperationRegistry::all(),
            'versions' => $versions,
            'logs' => $logs,

            // ══════════════════════════════════════════════════════════
            // **§14 — أسماءٌ لا أرقامَ هويّات.**
            //
            // كان السجلُّ يعرض `admin_id = 7`. ومن يراجع تغييراً ماليّاً
            // بعد شهرين لا يعرف من «٧»، **فالتدقيقُ يصير قائمةَ أرقام** —
            // موجودةً ولا تُجيب سؤالَ «من فعل هذا؟».
            'actors' => $this->actorNames(
                $logs->pluck('admin_id')->filter()->unique()->values()->all()),
        ]);
    }

    /**
     * أرقامُ الهويّات ⇒ أسماء.
     *
     * @return array<int,string>
     */
    private function actorNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (User::query()->whereIn('id', $ids)->get() as $u) {
            $name = trim(((string) $u->f_name).' '.((string) $u->l_name));

            // **ولا خانةَ فارغة**: حسابٌ بلا اسمٍ يُعرَض برقمه صراحةً،
            // فالفراغُ في التدقيق يُقرأ «لا فاعل».
            $out[(int) $u->id] = $name !== '' ? $name : ('مستخدم #'.$u->id);
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    // الكتابة
    // ══════════════════════════════════════════════════════════════════

    public function webCreate(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        $op = $code !== '' ? FeeOperationRegistry::find($code) : null;

        if ($code !== '' && $op === null) {
            abort(404);
        }

        $zone = $this->safeZone($request->query('zone'));
        $appliesTo = trim((string) $request->query('applies_to', ''));

        // **والجهةُ لا تُقبل إن لم تكن من جهات العمليّة** — وإلّا فُتح
        // النموذجُ على تركيبةٍ يرفضها الحفظُ بعد ملئها كلِّها.
        if ($op !== null && ! in_array($appliesTo, $op->actors, true)) {
            $appliesTo = $op->actors[0];
        }

        $current = ($op !== null && $appliesTo !== '')
            ? $this->feeService->activeScheme($code, $zone, $appliesTo)
            : null;

        return view('admin-views.amial.fees.create', [
            'tabs' => $this->tabs('overview'),
            'operations' => FeeOperationRegistry::all(),
            'operationsJson' => FeeOperationRegistry::toArray(),
            'categories' => FeeOperationRegistry::CATEGORIES,
            'feeTypes' => FeeScheme::FEE_TYPES,
            'zones' => $this->zones(),
            'zone' => $zone,
            'op' => $op,
            'prefillCode' => $code,
            'appliesTo' => $appliesTo,
            'current' => $current,
        ]);
    }

    public function webStore(Request $request)
    {
        $data = $this->validateInput($request);

        try {
            $scheme = $this->feeService->createVersion(
                $data,
                $request->user()?->id,
                $request->ip()
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['fee' => $e->getMessage()]);
        } catch (\Illuminate\Database\QueryException $e) {
            // ══════════════════════════════════════════════════════════
            // **تصادمُ القيد الفريد** — مديران حفظا النسخةَ نفسَها في
            // اللحظة نفسِها. ورسالةُ المحرّك الخام (`1062 Duplicate entry`)
            // لا يفهمها من يقرؤها، **فيُعيد المحاولةَ ظانّاً أنّ الحفظَ فشل**
            // وقد نجح غيرُه.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return back()->withInput()->withErrors(['fee' =>
                    'حُفظت نسخةٌ أخرى لهذه العمليّة في اللحظة نفسِها. '
                    . 'افتح الصفحةَ من جديدٍ لترى التسعيرةَ الحاليّة ثمّ أعِد المحاولة.']);
            }

            throw $e;
        }

        return redirect()
            ->route('admin.amial.fees.index', ['zone' => $scheme->zone_code])
            ->with('success', sprintf('تمّ إنشاء النسخة v%d للعمليّة «%s» — وتسري الآن.',
                $scheme->version, FeeOperationRegistry::label($scheme->code)));
    }

    public function webDeactivate(Request $request, int $id)
    {
        $scheme = FeeScheme::findOrFail($id);

        // **وسببٌ إلزاميّ** — تعطيلُ تسعيرةٍ يجعل العمليّةَ مجّانيّةً حتّى
        // تُضبط نسخةٌ جديدة. وقرارٌ بهذا الأثر لا يُترك بلا سببٍ مكتوبٍ
        // يقرؤه من يراجع بعد شهر.
        $reason = trim((string) $request->input('reason', ''));

        if (mb_strlen($reason) < 5) {
            return back()->withErrors(['fee' =>
                'اكتب سببَ التعطيل (‏٥ أحرفٍ فأكثر) — فالعمليّةُ تصير بلا رسمٍ بعده.']);
        }

        $this->feeService->deactivate($id, $request->user()?->id, $request->ip(), $reason);

        return back()->with('success', sprintf(
            'عُطّلت نسخةُ «%s» v%d — والعمليّةُ الآن بلا رسمٍ حتّى تُضبط نسخةٌ جديدة.',
            FeeOperationRegistry::label($scheme->code), $scheme->version));
    }

    /**
     * محاكٍ حيّ — نسخةٌ افتراضيّةٌ + مبلغٌ ⇒ التفصيلُ بلا حفظ.
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-FEE-TRUTH-017 — وكانت المنطقةُ تُقرأ خاماً من الطلب.**
     *
     * فقيمةٌ لا وجودَ لها تمرّ إلى الحساب، والشاشةُ تعرض منطقةً ويحسب
     * المحاكي بأخرى. ومن جرّب تسعيرةَ منطقةٍ رأى رقماً **ليس رقمَها**،
     * فاعتمده وحفظ نسخةً على أساسه. (‏«محاكٍ يخالف الإنتاج» صفرٌ في
     * تعريف الإغلاق.)
     */
    public function simulate(Request $request): JsonResponse
    {
        $amount = (string) $request->input('amount', 0);

        if (! is_numeric($amount) || (float) $amount < 0) {
            return response()->json([
                'success' => false,
                'message' => 'المبلغُ غيرُ صالح — يُكتب رقماً موجباً.',
            ], 422);
        }

        try {
            $data = [
                'code' => (string) $request->input('code', 'SEND_MONEY'),
                'zone_code' => $this->safeZone($request->input('zone_code')),
                'applies_to' => (string) $request->input('applies_to', 'customer'),
                'fee_type' => (string) $request->input('fee_type', 'percent'),
                'percent_rate' => $request->input('percent_rate', 0),
                'fixed_amount' => $request->input('fixed_amount', 0),
                'min_fee' => $request->input('min_fee'),
                'max_fee' => $request->input('max_fee'),
                'agent_commission_percent' => $request->input('agent_commission_percent', 0),
                'agent_commission_fixed' => $request->input('agent_commission_fixed', 0),
                'bearer' => (string) $request->input('bearer', 'sender'),
            ];

            $result = $this->feeService->simulate($data, $amount);

            return response()->json(['success' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // مساعدات
    // ══════════════════════════════════════════════════════════════════

    /**
     * **المنطقةُ تُقرأ من مصدرها ولا تُقبل خاماً.**
     *
     * فقيمةٌ من المتصفّح تدخل استعلامَ التسعيرة، وقيمةٌ لا وجودَ لها تُنتج
     * «لا نسخةَ نشطة» — أي **صفراً يُقرأ مجّانيّة**.
     * (‏القاعدة الثامنة: الهويّة تحدّد النطاق، لا القائمة المنسدلة.)
     */
    private function safeZone(mixed $raw): string
    {
        $z = strtoupper(trim((string) ($raw ?? '')));

        return in_array($z, ZonePolicyService::VALID_ZONES, true)
            ? $z
            : ZonePolicyService::ALLOWED_OPERATIONAL_ZONE;
    }

    /**
     * نطاقُ التقرير — **و«من» لا تتجاوز «إلى»**.
     *
     * فنطاقٌ مقلوبٌ يُخرج صفراً في كلّ خانة، **والصفرُ يُقرأ «لا ربحَ في
     * الفترة»** لا «سألتَ سؤالاً مقلوباً». (‏القاعدة السابعة.)
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    private function period(Request $request): array
    {
        try {
            $from = $request->filled('from')
                ? \Carbon\Carbon::parse((string) $request->query('from'))->startOfDay()
                : now()->startOfMonth();

            $to = $request->filled('to')
                ? \Carbon\Carbon::parse((string) $request->query('to'))->endOfDay()
                : now()->endOfDay();
        } catch (\Throwable $e) {
            // **تاريخٌ لا يُفهَم لا يُسقط الصفحة** — يُردّ إلى الافتراضيّ.
            $from = now()->startOfMonth();
            $to = now()->endOfDay();
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        // **وحدٌّ على السَّعة** — منحنىً بألف يومٍ يُثقل الصفحةَ ولا يُقرأ.
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * أيملك هذا المستخدمُ حقَّ التعديل؟
     *
     * **لتُخفى الأزرارُ لا لتُعطَّل الحراسة**: الحارسُ الحقيقيُّ في المسار
     * (`platform:platform.fees.update`)، وهذا لئلّا يُعرَض زرٌّ يردّ ٤٠٣.
     * (‏القاعدة التاسعة: زرٌّ لا يفعل شيئاً زرٌّ يكذب.)
     */
    private function canWrite(Request $request): bool
    {
        $u = $request->user();

        if ($u === null) {
            return false;
        }

        foreach (['hasPlatformPermission', 'hasPermission', 'can'] as $m) {
            if (method_exists($u, $m)) {
                try {
                    return (bool) $u->{$m}('platform.fees.update');
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        // **ولا يُخمَّن «لا»**: من وصل إلى هذه الصفحة يملك `fees.view` على
        // الأقلّ، ومنعُ الأزرار عنه بلا دليلٍ يُعطّل عملاً مشروعاً — والمسارُ
        // نفسُه محروسٌ فلا يُفتح باب.
        return true;
    }

    private function validateInput(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'in:'.implode(',', FeeScheme::codes())],
            'label' => ['nullable', 'string', 'max:120'],
            'zone_code' => ['required', 'in:'.implode(',', ZonePolicyService::VALID_ZONES)],
            'applies_to' => ['required', 'in:'.implode(',', FeeScheme::APPLIES_TO)],
            // AMIAL-FEE-PLAN-001 — والفراغُ مقبولٌ ومعناه «كلُّ الباقات».
            'plan' => ['nullable', 'in:'.implode(',', \App\Support\Access\AccessConstants::ALL_PLANS)],
            'fee_type' => ['required', 'in:'.implode(',', FeeScheme::FEE_TYPES)],
            'percent_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['required', 'numeric', 'min:0'],
            'min_fee' => ['nullable', 'numeric', 'min:0'],
            'max_fee' => ['nullable', 'numeric', 'min:0', 'gte:min_fee'],
            'agent_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'agent_commission_fixed' => ['required', 'numeric', 'min:0'],
            'bearer' => ['required', 'in:'.implode(',', FeeScheme::BEARERS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validator->validate();

        return $validator->validated();
    }
}
