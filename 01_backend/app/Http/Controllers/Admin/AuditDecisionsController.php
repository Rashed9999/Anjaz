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
            'filters' => $request->only([
                'decision_code', 'severity', 'actor_user_id', 'action',
                'subject_type', 'subject_id', 'date_from', 'date_to',
            ]),
        ]);
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
            'decision_code' => $d->decision_code,
            'severity' => $d->severity,
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

            'subject' => [
                'type' => $d->subject_type,
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

            fputcsv($out, ['المعرّف', 'الوقت', 'المنفِّذ', 'نوع المنفِّذ', 'الفعل',
                'رمز القرار', 'الدرجة', 'الموضوع', 'معرّف الموضوع',
                'المعاملة', 'النطاق', 'السبب', 'بصمة السجلّ']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->decision_id,
                    (string) $r->created_at,
                    $names[$r->actor_user_id] ?? ($r->actor_user_id ? '#' . $r->actor_user_id : '—'),
                    $r->actor_type,
                    $r->action,
                    $r->decision_code,
                    $r->severity,
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
    private function chainStatus(): array
    {
        $rows = AuditDecision::orderByDesc('id')->limit(100)->get()->reverse()->values();

        if ($rows->isEmpty()) {
            // **«غير معروف» ليس «سليم»** — القاعدة السابعة.
            return ['checked' => 0, 'broken' => 0, 'state' => 'empty'];
        }

        $broken = 0;
        $prev = null;

        foreach ($rows as $r) {
            if ($prev !== null && $r->prev_hash !== $prev->entry_hash) {
                $broken++;
            }

            if (! $this->verifyOne($r)['hash_matches']) {
                $broken++;
            }

            $prev = $r;
        }

        return [
            'checked' => $rows->count(),
            'broken' => $broken,
            'state' => $broken === 0 ? 'ok' : 'broken',
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

        return [
            'entry_hash' => $d->entry_hash,
            'prev_hash' => $d->prev_hash,
            // AMIAL-AUDIT-JSON-001 — الشكلُ القانونيُّ أو الخامُّ القديم.
            // فعلى MySQL 8 كان كلُّ سجلٍّ سليمٍ يُعرض «معبوثاً به».
            'hash_matches' => AuditService::hashMatches(
                (string) $d->prev_hash, $attrs, (string) $d->entry_hash),
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
        if ($action = $request->query('action')) {
            $query->where('action', 'like', "%{$action}%");
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

        return $query;
    }

    /** اسمُ الموضوع كما يُقرأ — لا `user / 42`. */
    private function subjectLabel(AuditDecision $d): string
    {
        $id = (string) $d->subject_id;

        return match ($d->subject_type) {
            'user' => 'حساب #' . $id,
            'transaction' => 'معاملة ' . $id,
            'wallet' => 'محفظة #' . $id,
            'merchant' => 'تاجر #' . $id,
            'session' => 'جلسة ' . $id,
            'pin' => 'رمز حماية لحساب #' . $id,
            'support_ticket' => 'تذكرة دعم #' . $id,
            default => trim(($d->subject_type ?? '—') . ' ' . $id),
        };
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
