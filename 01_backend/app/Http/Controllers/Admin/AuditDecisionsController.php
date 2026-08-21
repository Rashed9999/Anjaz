<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditDecision;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-AUDIT-001 (v0.8) · AMIAL-AUDIT-DETAIL-002
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن الذي دُفع:** فتح صاحبُ المشروع «سجلّ تدقيق النظام» فرأى جدولاً
 * من ستّة أعمدة: الوقت · المنفِّذ · الفعل · القرار · الدرجة · السبب.
 * وسأل: «لماذا لا يوجد تفاصيل أكثر وأزرار أكثر حقيقيّة؟».
 *
 * **والسؤال في محلّه**: الجدولُ كان يعرض ستّةً من **سبعة عشر عموداً**،
 * ويُخفي كلَّ ما يجعل السجلَّ سجلَّ تدقيق:
 *
 *   · `context` — القصّةُ كاملةً (ما قبل، وما بعد، والمبلغ، والجهاز).
 *   · `transaction_id` — المعاملةُ التي يخصّها القرار.
 *   · `subject_type/subject_id` — على **مَن** وقع القرار.
 *   · `decision_id` — المعرّفُ الذي يُحال إليه في المراسلات.
 *   · `idempotency_key` · `zone_code`.
 *   · **`prev_hash` و`entry_hash` — سلسلةُ البصمات.**
 *
 * وأخطرُها الأخير: السلسلةُ مبنيّةٌ منذ `AuditService`، **ولا شيءَ
 * يتحقّق منها في أيّ شاشة**. وسلسلةٌ مقاوِمةٌ للعبث لا يُتحقَّق منها
 * ليست مقاوِمةً للعبث — هي عمودان في جدول. (والمهارة تقول: «Can Audit
 * reproduce the history؟» — ولا سبيل قبل هذا.)
 *
 * **و«المنفِّذ» كان يُعرض `admin#8`** — رقمٌ يحتاج استعلاماً آخرَ ليصير
 * اسماً. فمن يقرأ مئةَ صفٍّ يقرأ مئةَ رقم.
 */
class AuditDecisionsController extends Controller
{
    /** حدُّ صفوف التصدير — ملفٌّ بلا حدٍّ يُسقط الذاكرة على جدولٍ كبير. */
    private const EXPORT_LIMIT = 20000;

    /**
     * نتيجةُ فحص السلسلة محفوظةٌ للطلب الواحد.
     *
     * فهي خمسُ مئةِ عمليّةِ تجزئة، وتُسأل مرّتين في الصفحة الواحدة
     * (المرشِّحُ واللافتة) — فحسبُها مرّتين يضاعف الكلفةَ بلا فائدة.
     *
     * @var array<string,mixed>|null
     */
    private ?array $chainCache = null;

    public function index(Request $request)
    {
        $query = $this->filtered($request);

        $decisions = $query->paginate(50)->withQueryString();

        // **الأسماءُ تُجلب دفعةً واحدة** — لا استعلاماً لكلّ صفّ.
        // (خمسون صفّاً × استعلامٍ لكلٍّ = خمسون رحلةً إلى القاعدة.)
        $actors = User::whereIn('id', collect($decisions->items())
            ->pluck('actor_user_id')->filter()->unique()->all())
            ->pluck('f_name', 'id');

        // إحصاءات سريعة (آخر 24h)
        $stats24h = AuditDecision::where('created_at', '>=', now()->subHours(24))
            ->selectRaw('severity, COUNT(*) as cnt')
            ->groupBy('severity')
            ->pluck('cnt', 'severity')
            ->toArray();

        return view('admin-views.amial.audit.index', [
            'decisions' => $decisions,
            'actors' => $actors,
            'stats_24h' => $stats24h,
            'chain' => $this->chainStatus(),
            // **`transaction_id` كان مبنيّاً في `filtered()` ولا مدخلَ له.**
            //
            // القاعدة الثانية عشرة: مبنيٌّ ولا يُوصَل إليه. المرشِّحُ يعمل
            // منذ كُتب، ولا حقلَ في النموذج ولا هو في هذه القائمة — فحتّى
            // من مرّره في العنوان لم يَرَه يعود في الحقل بعد الفلترة.
            'filters' => $request->only([
                'decision_code', 'severity', 'actor_user_id', 'action',
                'subject_type', 'subject_id', 'date_from', 'date_to',
                'transaction_id', 'q', 'zone_code', 'domain', 'integrity',
            ]),
            'domains' => \App\Support\AuditVocabulary::DOMAINS,
            'actions_by_domain' => $this->actionsPresent(),
            'severities' => \App\Support\AuditVocabulary::severities(),
            'subject_types' => $this->subjectTypesPresent(),
        ]);
    }

    /**
     * الأفعالُ **الموجودةُ فعلاً**، مجمّعةً بمجالها.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا تُبنى القائمةُ من المعجم وحدَه.** فالمعجمُ يعرف ١٤٧ رمزاً
     * مسمّىً، والجدولُ فيه رموزٌ منقوطةٌ يُركَّب بعضُها في وقت التشغيل
     * (`agent.teller.` + الحدث). فقائمةٌ من المعجم تُسقط من المرشِّح
     * كلَّ فعلٍ لم يُصنَّف — **وهي أفعالُ الوكيل كلُّها**، أي ما يملأ
     * الشاشةَ فعلاً.
     *
     * فتُسأل البيانات، ويُترجَم ما له ترجمة، **ويُعرَض الباقي بخامّه
     * تحت «غيرُ مصنّف»** — فيبقى قابلاً للتصفية وإن لم يكن مقروءاً.
     *
     * @return array<string,array<string,string>>
     */
    private function actionsPresent(): array
    {
        $codes = AuditDecision::query()
            ->select('action')->distinct()
            ->orderBy('action')->limit(400)->pluck('action');

        $out = [];

        foreach ($codes as $code) {
            if ((string) $code === '') {
                continue;
            }

            $a = \App\Support\AuditVocabulary::action($code);
            $group = $a['domain'] ?? '__unclassified__';

            $out[$group][$code] = $a['translated'] ? $a['label'] : $code;
        }

        foreach (array_keys($out) as $g) {
            asort($out[$g]);
        }

        // «غيرُ المصنّف» آخِراً — فالمعروفُ يُقرأ أوّلاً.
        if (isset($out['__unclassified__'])) {
            $tail = $out['__unclassified__'];
            unset($out['__unclassified__']);
            $out['__unclassified__'] = $tail;
        }

        return $out;
    }

    /**
     * أنواعُ المواضيع **الموجودةُ فعلاً في الجدول** — لا قائمةٌ مكتوبة.
     *
     * فقائمةٌ مكتوبةٌ في الشيفرة تشيخ: يُضاف نوعٌ في خدمةٍ ولا يظهر في
     * المرشِّح أبداً، فيبقى مالٌ أو حسابٌ لا سبيلَ لتصفيته. **والقائمةُ
     * تُسأل من البيانات فتصدق دائماً.**
     *
     * @return array<string,string>
     */
    private function subjectTypesPresent(): array
    {
        $rows = AuditDecision::query()
            ->select('subject_type')->distinct()
            ->orderBy('subject_type')->limit(60)->pluck('subject_type');

        $out = [];

        foreach ($rows as $t) {
            if ((string) $t === '') {
                continue;
            }

            $out[$t] = \App\Support\AuditVocabulary::subjectType($t);
        }

        return $out;
    }

    /**
     * **القرارُ كاملاً** — كلُّ ما في الصفّ، ومعه ما يُفهمه.
     */
    public function show(int $id): JsonResponse
    {
        $d = AuditDecision::find($id);

        if (! $d) {
            return response()->json(['success' => false, 'message' => 'القرار غير موجود'], 404);
        }

        $actor = $d->actor_user_id ? User::find($d->actor_user_id, ['id', 'f_name', 'l_name', 'phone', 'type']) : null;

        return response()->json(['success' => true, 'data' => [
            'id' => (int) $d->id,
            'decision_id' => $d->decision_id,
            'created_at' => (string) $d->created_at,
            'action' => $d->action,
            // **العربيّةُ تُحسب في الخادم لا في المتصفّح.**
            // فمعجمٌ ثانٍ في جافاسكربت يفترق عن الأوّل بعد أسبوعين،
            // فيقرأ الجدولُ شيئاً واللوحُ شيئاً آخرَ للصفّ نفسِه.
            'action_ar' => \App\Support\AuditVocabulary::action($d->action),
            'decision_code' => $d->decision_code,
            'decision_ar' => \App\Support\AuditVocabulary::decisionCode($d->decision_code),
            'severity' => $d->severity,
            'severity_ar' => \App\Support\AuditVocabulary::severity($d->severity),
            'reason' => $d->reason,
            'zone_code' => $d->zone_code,
            'transaction_id' => $d->transaction_id,
            'idempotency_key' => $d->idempotency_key,

            'actor' => $actor ? [
                'id' => (int) $actor->id,
                'name' => trim(($actor->f_name ?? '') . ' ' . ($actor->l_name ?? '')) ?: 'بلا اسم',
                'phone' => $actor->phone,
                'type' => (int) $actor->type,
            ] : null,
            'actor_type' => $d->actor_type,
            'actor_type_ar' => \App\Support\AuditVocabulary::actorType($d->actor_type),

            'subject' => [
                'type' => $d->subject_type,
                'type_ar' => \App\Support\AuditVocabulary::subjectType($d->subject_type),
                'id' => $d->subject_id,
                'label' => $this->subjectLabel($d),
                // **ورابطٌ يُنقر** — رقمٌ لا يُفضي إلى شيءٍ ليس تتبّعاً.
                'url' => $this->subjectUrl($d),
            ],

            'context' => $this->readableContext($d),

            // **سلسلةُ البصمات — تُتحقَّق لا تُعرض فقط.**
            'integrity' => $this->verifyOne($d),
        ]]);
    }

    /**
     * **تصديرُ ما هو معروضٌ الآن** — بالفلاتر نفسِها لا بالجدول كلّه.
     *
     * فمُصدِّرٌ يتجاهل الفلتر يُخرج ملفّاً لا علاقةَ له بالشاشة، ومن
     * يفتحه يظنّ أنّ بحثَه لم يعمل.
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request)->limit(self::EXPORT_LIMIT)->get();

        $names = User::whereIn('id', $rows->pluck('actor_user_id')->filter()->unique()->all())
            ->pluck('f_name', 'id');

        $name = 'amial-audit-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows, $names) {
            $out = fopen('php://output', 'w');

            // BOM: بلاه تُقرأ العربيّةُ رموزاً في Excel.
            fwrite($out, "\xEF\xBB\xBF");

            // **العربيّةُ والرمزُ معاً — لا إحداهما.**
            //
            // فالعربيّةُ وحدَها لا تُبحث في نظامٍ آخرَ ولا تُلصَق في تذكرة،
            // والرمزُ وحدَه لا يقرؤه من يفتح الملفّ. والتصديرُ يخرج إلى
            // مدقّقٍ خارجيٍّ لا يعرف رموزنا.
            fputcsv($out, ['المعرّف', 'الوقت', 'المنفِّذ', 'صفةُ المنفِّذ',
                'الفعل', 'رمزُ الفعل', 'القرار', 'رمزُ القرار', 'الدرجة',
                'الموضوع', 'نوعُ الموضوع (رمز)', 'معرّفُ الموضوع',
                'المعاملة', 'النطاق', 'السبب', 'بصمةُ السجلّ']);

            foreach ($rows as $r) {
                $action = \App\Support\AuditVocabulary::action($r->action);

                fputcsv($out, [
                    $r->decision_id,
                    (string) $r->created_at,
                    $names[$r->actor_user_id] ?? ($r->actor_user_id ? '#' . $r->actor_user_id : '—'),
                    \App\Support\AuditVocabulary::actorType($r->actor_type),
                    $action['translated'] ? $action['label'] : '(بلا ترجمة)',
                    $r->action,
                    \App\Support\AuditVocabulary::decisionCode($r->decision_code)['label'],
                    $r->decision_code,
                    \App\Support\AuditVocabulary::severity($r->severity)['label'],
                    \App\Support\AuditVocabulary::subjectType($r->subject_type),
                    $r->subject_type,
                    $r->subject_id,
                    $r->transaction_id,
                    $r->zone_code,
                    $r->reason,
                    $r->entry_hash,
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  السلسلة
    // ══════════════════════════════════════════════════════════════════

    /**
     * **حالةُ آخر مئة قرار** — تُعرض فوق الجدول.
     *
     * ولا تُفحص السلسلةُ كلُّها في كلّ فتحة: على مليون صفٍّ تعني ذلك
     * مليونَ عمليّةِ تجزئةٍ لكلّ زيارة. الفحصُ الكاملُ أمرٌ مجدول
     * (`amial:audit-verify`)، وهذا مسبارٌ سريعٌ على الطرف الحيّ.
     */
    /** حجمُ نافذة الفحص السريع فوق الجدول. */
    private const CHAIN_WINDOW = 500;

    private function chainStatus(): array
    {
        return $this->chainCache ??= $this->computeChainStatus();
    }

    private function computeChainStatus(): array
    {
        $rows = AuditDecision::orderByDesc('id')
            ->limit(self::CHAIN_WINDOW)->get()->reverse()->values();

        if ($rows->isEmpty()) {
            // **«غير معروف» ليس «سليم»** — القاعدة السابعة.
            return ['checked' => 0, 'state' => 'empty',
                'unsigned' => 0, 'tampered' => 0, 'link_breaks' => 0,
                'broken' => 0, 'ids' => [], 'ids_unsigned' => [],
                'ids_broken' => [], 'first_signed_id' => null];
        }

        $unsigned = 0;      // كُتب قبل وجود السلسلة — **لم يُوقَّع قطّ**
        $tampered = 0;      // بصمةٌ موجودةٌ ولا تطابق المحتوى — **عبثٌ**
        $linkBreaks = 0;    // حلقةٌ لا تصل بسابقتها
        $unsignedIds = [];
        $brokenIds = [];
        $prev = null;

        foreach ($rows as $r) {
            $signed = (string) $r->entry_hash !== '';

            if (! $signed) {
                // ══════════════════════════════════════════════════════
                // **وهذا هو تصحيحُ الشاشة كلِّها.**
                //
                // عمودا `prev_hash` و`entry_hash` أُضيفا في هجرة
                // `2026_07_05_120000` **بعد** إنشاء الجدول بشهرٍ ونصف،
                // ونُصّا `nullable`. فكلُّ قرارٍ كُتب قبل ذلك اليوم
                // **يحمل فراغين** — لا لأنّ أحداً مسّه، بل لأنّ السلسلة
                // لم تكن قد وُجدت بعد.
                //
                // وكان الحسابُ القديم يعدّه «مكسوراً» **مرّتين**: مرّةً
                // لأنّ بصمتَه لا تطابق (وهي غيرُ موجودة)، ومرّةً لأنّ
                // تاليه لا يصل به. فتخرج اللافتةُ الحمراء «عُبث
                // بالسجلّ» على سجلٍّ لم يمسّه أحد.
                //
                // **وحارسٌ يكذب أسوأ من غيابه**: يُرسل من يصدّقه في
                // تحقيقٍ داخليٍّ خلف عبثٍ لا وجودَ له، ثمّ — وهو الأسوأ —
                // يُعوّده أن يتجاهل اللافتةَ يومَ تصدق.
                // ══════════════════════════════════════════════════════
                $unsigned++;
                $unsignedIds[] = (int) $r->id;
                $prev = $r;

                continue;
            }

            if (! $this->verifyOne($r)['hash_matches']) {
                $tampered++;
                $brokenIds[] = (int) $r->id;
            }

            // حلقةٌ لا تصل بسابقتها — **ولا تُحسب على الحدّ مع صفٍّ غير
            // موقَّع**، فذاك انقطاعُ بدايةٍ لا انقطاعُ سلسلة.
            if ($prev !== null && (string) $prev->entry_hash !== ''
                && $r->prev_hash !== $prev->entry_hash) {
                $linkBreaks++;
                $brokenIds[] = (int) $r->id;
            }

            $prev = $r;
        }

        $unsignedIds = array_values(array_unique($unsignedIds));
        $brokenIds = array_values(array_unique($brokenIds));

        return [
            'checked' => $rows->count(),
            'unsigned' => $unsigned,
            'tampered' => $tampered,
            'link_breaks' => $linkBreaks,
            'broken' => $tampered + $linkBreaks,
            'ids' => array_slice(array_values(array_unique(
                array_merge($brokenIds, $unsignedIds))), 0, 400),
            'ids_unsigned' => array_slice($unsignedIds, 0, 400),
            'ids_broken' => array_slice($brokenIds, 0, 400),
            'first_signed_id' => (int) (AuditDecision::whereNotNull('entry_hash')
                ->min('id') ?: 0),
            // **ثلاثُ حالاتٍ لا اثنتان.** و`legacy` ليست `broken`.
            'state' => match (true) {
                $tampered > 0 || $linkBreaks > 0 => 'broken',
                $unsigned > 0 => 'legacy',
                default => 'ok',
            },
        ];
    }

    /**
     * أتُطابق بصمةُ الصفّ محتواه؟
     *
     * تُعاد الحسبةُ بالدالّة نفسِها التي كتبت البصمة — فأيُّ تعديلٍ
     * مباشرٍ في القاعدة يُنتج بصمةً مختلفة.
     */
    private function verifyOne(AuditDecision $d): array
    {
        $attrs = [
            'decision_id' => $d->decision_id,
            'actor_type' => $d->actor_type,
            'actor_user_id' => $d->actor_user_id,
            'subject_type' => $d->subject_type,
            'subject_id' => $d->subject_id,
            'action' => $d->action,
            'decision_code' => $d->decision_code,
            'reason' => $d->reason,
            'context' => $d->getRawOriginal('context'),
            'transaction_id' => $d->transaction_id,
            'zone_code' => $d->zone_code,
            'severity' => $d->severity,
        ];

        // **صفٌّ بلا بصمةٍ ليس صفّاً معبوثاً به** — هو صفٌّ لم يُوقَّع قطّ،
        // لأنّ عمودَي السلسلة أُضيفا بعد إنشاء الجدول بشهرٍ ونصف. والفرقُ
        // بين «لم يُوقَّع» و«وُقّع ثمّ غُيّر» هو الفرقُ بين تاريخٍ قديم
        // وجريمة.
        if ((string) $d->entry_hash === '') {
            return [
                'entry_hash' => null,
                'prev_hash' => $d->prev_hash,
                'hash_matches' => false,
                'verdict' => 'unsigned',
                'verdict_label' => 'كُتب قبل إنشاء السلسلة — لا بصمةَ له، ولم يُمسّ',
            ];
        }

        // AMIAL-AUDIT-JSON-001 — الشكلُ القانونيُّ أو الخامُّ القديم.
        // فعلى MySQL 8 كان كلُّ سجلٍّ سليمٍ يُعرض «معبوثاً به».
        $ok = AuditService::hashMatches(
            (string) $d->prev_hash, $attrs, (string) $d->entry_hash);

        return [
            'entry_hash' => $d->entry_hash,
            'prev_hash' => $d->prev_hash,
            'hash_matches' => $ok,
            'verdict' => $ok ? 'ok' : 'tampered',
            'verdict_label' => $ok
                ? 'البصمةُ تطابق المحتوى'
                : 'البصمةُ لا تطابق المحتوى — عُدِّل هذا الصفّ بعد كتابته',
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  مساعِدات العرض
    // ══════════════════════════════════════════════════════════════════

    private function filtered(Request $request)
    {
        $query = AuditDecision::query()->orderByDesc('id'); // الترتيب بالـ id أسرع من created_at

        if ($code = $request->query('decision_code')) {
            $query->where('decision_code', $code);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }
        if ($actor = $request->query('actor_user_id')) {
            $query->where('actor_user_id', (int) $actor);
        }
        // **مطابقةٌ تامّة لا `like`.**
        //
        // صار الفعلُ قائمةً منسدلةً تُرسل الرمزَ كاملاً، و`%hold%` كانت
        // تلتقط `TRANSACTION_BLOCKED_BY_HOLD` مع `hold` — فيُقرأ العدُّ
        // أكبرَ ممّا هو، ولا شيءَ يقول إنّ المرشِّح وسّع نفسَه.
        // والبحثُ الجزئيُّ بابُه «بحثٌ حرّ» أعلاه.
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        // AMIAL-SAFEPAY-AUDIT-001: «أرني كل ما جرى على هذا الشيء بعينه».
        if ($subjectType = $request->query('subject_type')) {
            $query->where('subject_type', $subjectType);
        }
        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', (string) $subjectId);
        }
        if ($from = $request->query('date_from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        // **والمعاملةُ فلترٌ أيضاً** — «أرني كلّ ما جرى على هذا التحويل».
        if ($tx = $request->query('transaction_id')) {
            $query->where('transaction_id', $tx);
        }

        if ($zone = $request->query('zone_code')) {
            $query->where('zone_code', $zone);
        }

        // ══════════════════════════════════════════════════════════════
        // **بحثٌ حرٌّ في السبب والفعل والرمز.**
        //
        // فالمحقّقُ يبدأ من كلمةٍ سمعها — «رفض»، «خارج النطاق» — لا من
        // رمزٍ يحفظه. وثلاثةُ حقولٍ منفصلةٍ تُلزمه بمعرفة أيَّها يخصّه.
        // ══════════════════════════════════════════════════════════════
        if ($q = trim((string) $request->query('q'))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

            $query->where(function ($w) use ($like) {
                $w->where('reason', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('decision_code', 'like', $like)
                    ->orWhere('decision_id', 'like', $like);
            });
        }

        // ══════════════════════════════════════════════════════════════
        // **المجالُ مرشِّحٌ على مجموعةِ أفعالٍ معروفة.**
        //
        // «أرني كلَّ ما يخصّ الخير» بدل تذكّرِ تسعةِ رموزٍ وتهجئتِها.
        // وأفعالُ المجال تُؤخذ من المعجم — فما لا ترجمةَ له لا يُدَّعى
        // انتماؤه إلى مجالٍ لم يُصنَّف فيه.
        // ══════════════════════════════════════════════════════════════
        // ══════════════════════════════════════════════════════════════
        // **«أرني المشبوهَ وحدَه».**
        //
        // ولا يُصفّى في SQL: الحكمُ يحتاج إعادةَ حساب التجزئة صفّاً صفّاً.
        // فيُقصَر على النافذة المفحوصة (٥٠٠ قرار) — **وتقول الشاشةُ ذلك
        // صراحةً**، فمرشِّحٌ يبدو شاملاً وهو على نافذةٍ يُخفي ما خارجها.
        // ══════════════════════════════════════════════════════════════
        if (($integrity = $request->query('integrity')) !== null && $integrity !== '') {
            $chain = $this->chainStatus();

            $ids = match ($integrity) {
                'unsigned' => $chain['ids_unsigned'],
                'broken' => $chain['ids_broken'],
                default => $chain['ids'],
            };

            $query->whereIn('id', $ids ?: [0]);
        }

        if ($domain = $request->query('domain')) {
            $codes = array_keys(
                \App\Support\AuditVocabulary::actionsByDomain()[$domain] ?? []);

            // مجالٌ بلا أفعالٍ معروفةٍ يُرجع لا شيء — **ولا يُتجاهَل
            // فيَعرِض السجلَّ كلَّه** وكأنّ المرشِّح عمل. (تسريبُ نطاقٍ
            // بصمتٍ هو ما وقع في بحث الدفتر من قبل.)
            $query->whereIn('action', $codes ?: ['__لا_مجالَ_بهذا_الاسم__']);
        }

        return $query;
    }

    /** اسمُ الموضوع كما يُقرأ — لا `user / 42`. */
    private function subjectLabel(AuditDecision $d): string
    {
        $id = (string) $d->subject_id;

        // **معجمٌ واحدٌ للأنواع كلِّها.** كانت هنا قائمةٌ ثانيةٌ من سبعة
        // أنواعٍ بالعربيّة، فما زاد عليها (`e_payment` · `safe_payment` ·
        // `agent_shift` …) يخرج بالإنجليزيّة — **وهو أكثرُ ممّا فيها**.
        $type = \App\Support\AuditVocabulary::subjectType($d->subject_type);

        if ($id === '') {
            return $type;
        }

        return $type . ' ' . (ctype_digit($id) ? '#' . $id : $id);
    }

    /**
     * **الرقمُ يُنقر فيُفضي إلى صاحبه** — «Every important number must be
     * clickable» (المهارة). ومن لا وجهةَ له يُرجع `null` فلا يُرسم رابطاً
     * ميّتاً.
     */
    private function subjectUrl(AuditDecision $d): ?string
    {
        $id = (int) $d->subject_id;

        if ($id <= 0) {
            return null;
        }

        return match ($d->subject_type) {
            'user', 'merchant', 'wallet', 'pin' => route('admin.amial.hub.account', $id),
            default => null,
        };
    }

    /**
     * السياقُ مصفوفةً مقروءة.
     *
     * و`AuditService` يُعمّي الحقولَ الحسّاسة عند الكتابة (`[REDACTED]`)،
     * فما يصل هنا آمنٌ للعرض — ولا يُعاد تعميتُه فيُقرأ فارغاً.
     */
    private function readableContext(AuditDecision $d): array
    {
        $ctx = $d->context;

        if (is_string($ctx)) {
            $ctx = json_decode($ctx, true);
        }

        return is_array($ctx) ? $ctx : [];
    }
}
