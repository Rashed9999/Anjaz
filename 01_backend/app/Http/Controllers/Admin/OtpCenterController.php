<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Messaging\Contracts\SupportsFreeText;
use App\Services\Messaging\ProviderRegistry;
use App\Services\Otp\OtpPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OTP-CENTER-001 — مركز التحقّق: يُدار من شاشة، لا من نشر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال الذي تُجيبه هذه الشاشة:**
 *
 *   «لماذا لا يصل رمزُ التحقّق؟»
 *
 * وكان جوابُه يحتاج فتحَ الخادم وقراءةَ السجلّات. صار في شاشةٍ واحدة:
 * أيُّ مزوّدٍ مُفعَّل، وأيُّهم ينقصه حقل، وكم محاولةً نجحت اليوم.
 *
 * **والثاني: «من يستطيع التسجيل بالرمز الثابت؟»** — وهو بابٌ في نظامٍ
 * ماليّ. فيُرى من يفتحه ومتى، ويُقفل بضغطة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * الصلاحيّة `platform.settings.update`: فتحُ بابِ تحقّقٍ أو إقفالُه ضبطُ
 * منصّةٍ لا خدمةُ عملاء. وموظّفُ الدعم لا يملكها.
 */
class OtpCenterController extends Controller
{
    public function __construct(
        private readonly OtpPolicy $policy,
        private readonly ProviderRegistry $registry,
    ) {}

    public function page()
    {
        return view('admin-views.amial.otp.index');
    }

    // ══════════════════════════════════════════════════════════════
    // القراءة
    // ══════════════════════════════════════════════════════════════

    /** المؤشّرات + صحّة المزوّدين + الرسم — كلُّها تُحسب، ولا تُكتب ثابتة. */
    public function stats(): JsonResponse
    {
        $numbers = $this->policy->demoNumbers();

        return $this->ok([
            'door_open'      => $numbers !== [] && $this->policy->demoCode() !== null,
            'demo_count'     => count($numbers),
            'delivery_ready' => $this->policy->deliveryReady(),
            'demo_code_set'  => $this->policy->demoCode() !== null,
            'today'          => $this->today(),
            'trend'          => $this->trend(),
            'providers'      => $this->providers(),
        ]);
    }

    /**
     * محاولاتُ اليوم.
     *
     * **و«لا محاولات» ليس «صفر نجاح»** (القاعدة السابعة): يوماً بلا
     * تسجيلٍ أصلاً تُعرض نسبتُه «—» لا «٠٪»، فصفرٌ يُقرأ عطلاً.
     */
    private function today(): array
    {
        $total = (int) DB::table('phone_verifications')
            ->whereDate('created_at', today())->count();

        $blocked = (int) DB::table('phone_verifications')
            ->whereDate('created_at', today())
            ->where('is_temp_blocked', 1)->count();

        return [
            'attempts' => $total,
            'blocked'  => $blocked,
            'success_rate' => $total > 0
                ? round((($total - $blocked) / $total) * 100, 1)
                : null,
        ];
    }

    /**
     * أربعةَ عشرَ يوماً — **رسمٌ يُفيد قراراً لا زينة**.
     *
     * فهبوطٌ مفاجئٌ إلى صفرٍ يعني أنّ التسجيل توقّف، وذاك ما لا يخبرك به
     * أحد: العميل الذي لم يستطع التسجيل لا يشتكي، يذهب.
     */
    private function trend(): array
    {
        $rows = DB::table('phone_verifications')
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) d, COUNT(*) n,
                         SUM(CASE WHEN is_temp_blocked = 1 THEN 1 ELSE 0 END) b')
            ->groupBy('d')->pluck('n', 'd');

        $blocked = DB::table('phone_verifications')
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) d, SUM(CASE WHEN is_temp_blocked = 1 THEN 1 ELSE 0 END) b')
            ->groupBy('d')->pluck('b', 'd');

        $out = [];

        for ($i = 13; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $out[] = [
                'date' => $d,
                'attempts' => (int) ($rows[$d] ?? 0),
                'blocked' => (int) ($blocked[$d] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * صحّةُ كلّ مزوّد — **وهو ما يُجيب «لماذا لا يصل الرمز؟»**.
     *
     * ولا يُقال «مُفعَّل» وحدها: مزوّدٌ `status=1` ينقصه `token` يبدو
     * أخضرَ ولا يُرسل. فيُقال أيُّ حقلٍ ينقصه بالاسم.
     */
    private function providers(): array
    {
        return array_map(function ($p) {
            return [
                'key' => $p->key(),
                'channel' => $p->channel(),
                'priority' => $p->priority(),
                'enabled' => $p->isEnabled(),
                'free_text' => $p instanceof SupportsFreeText,
            ];
        }, $this->registry->all());
    }

    /** قائمةُ الأرقام — بحثٌ وفرزٌ وترقيم. */
    public function numbers(Request $request): JsonResponse
    {
        $q = DB::table('otp_demo_numbers');

        if ($s = trim((string) $request->get('search', ''))) {
            $q->where(function ($x) use ($s) {
                $x->where('phone', 'like', "%{$s}%")->orWhere('label', 'like', "%{$s}%");
            });
        }

        if ($request->get('state') === 'active') {
            $q->where('is_active', true);
        } elseif ($request->get('state') === 'disabled') {
            $q->where('is_active', false);
        }

        $sort = in_array($request->get('sort'), ['phone', 'last_used_at', 'use_count', 'created_at'], true)
            ? $request->get('sort') : 'created_at';

        $rows = $q->orderBy($sort, $request->get('dir') === 'asc' ? 'asc' : 'desc')
            ->paginate(min((int) $request->get('per_page', 25), 100));

        return $this->ok([
            'rows' => $rows->items(),
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'pages' => $rows->lastPage(),

            // **البذرةُ تُعلَن**: الجدولُ فارغٌ ⇒ المتغيّرُ هو العامل.
            'from_env' => !DB::table('otp_demo_numbers')->exists(),
            'env_numbers' => (array) config('amial.otp.demo_numbers', []),
        ]);
    }

    /** تصديرُ CSV — الجدولُ يُخرَج كما يُقرأ. */
    public function export()
    {
        $rows = DB::table('otp_demo_numbers')->orderBy('phone')->get();

        $csv = "\xEF\xBB\xBF" . "الرقم,الوصف,الحالة,آخر استعمال,مرّات الاستعمال\n";

        foreach ($rows as $r) {
            $csv .= sprintf("%s,%s,%s,%s,%d\n",
                $r->phone, str_replace(',', ' ', (string) $r->label),
                $r->is_active ? 'مُفعَّل' : 'معطَّل',
                $r->last_used_at ?? '—', $r->use_count);
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="otp-demo-numbers.csv"',
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // الأفعال — ولكلٍّ تدقيقٌ يُكتب
    // ══════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|min:7|max:24',
            'label' => 'nullable|string|max:120',
        ]);

        $digits = preg_replace('/[^0-9]/', '', $data['phone']);

        if (strlen((string) $digits) < 7) {
            return $this->fail('رقمٌ غير صالح');
        }

        if (DB::table('otp_demo_numbers')->where('phone', $digits)->exists()) {
            return $this->fail('الرقم مضافٌ سلفاً');
        }

        DB::table('otp_demo_numbers')->insert([
            'phone' => $digits,
            'label' => $data['label'] ?? null,
            'is_active' => true,
            'added_by_user_id' => Auth::guard('user')->id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit('otp_demo_number.added', $digits);
        OtpPolicy::forget();

        return $this->ok(['phone' => $digits]);
    }

    /** يُعطَّل ولا يُحذف — أثرُ من فتح باباً لا يُمحى. */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $row = DB::table('otp_demo_numbers')->find($id);

        if (!$row) {
            return $this->fail('الرقم غير موجود');
        }

        DB::table('otp_demo_numbers')->where('id', $id)
            ->update(['is_active' => !$row->is_active, 'updated_at' => now()]);

        $this->audit($row->is_active ? 'otp_demo_number.disabled' : 'otp_demo_number.enabled', $row->phone);
        OtpPolicy::forget();

        return $this->ok(['is_active' => !$row->is_active]);
    }

    /**
     * **إقفالُ الباب كاملاً** — يوم الإطلاق.
     *
     * ويحتاج تأكيداً مكتوباً (`confirm=إقفال`) لا ضغطةً: فعلٌ يُوقف
     * حسابات العرض كلَّها، ومن يضغطه سهواً لا يعرف ما فعل حتّى يشتكي
     * أحدُهم. (كلُّ فعلٍ لا رجعة فيه يحتاج تأكيداً مزدوجاً.)
     */
    public function closeDoor(Request $request): JsonResponse
    {
        if ($request->get('confirm') !== 'إقفال') {
            return $this->fail('يلزم تأكيدٌ مكتوب');
        }

        $n = DB::table('otp_demo_numbers')->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        // **صفٌّ معطَّلٌ واحدٌ على الأقلّ** كي يصير الجدولُ مأهولاً فيغلب
        // البذرةَ — وإلّا لعاد `AMIAL_DEMO_PHONES` يفتح ما أُقفل.
        if (!DB::table('otp_demo_numbers')->exists()) {
            DB::table('otp_demo_numbers')->insert([
                'phone' => '0', 'label' => 'علامةُ إقفال — لا رقمَ حقيقيّ',
                'is_active' => false, 'added_by_user_id' => Auth::guard('user')->id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->audit('otp_demo_door.closed', (string) $n);
        OtpPolicy::forget();

        return $this->ok(['disabled' => $n]);
    }

    /** إرسالُ رسالةِ فحصٍ — يُثبت أنّ القناة تعمل قبل الاعتماد عليها. */
    public function testSend(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => 'required|string|min:7|max:24']);

        if (!$this->policy->deliveryReady()) {
            return $this->fail($this->policy->unavailableMessage());
        }

        $r = $this->registry->sendText('whatsapp', $data['phone'],
            'أميال باي — رسالةُ فحصٍ من مركز التحقّق. ' . now()->format('H:i'));

        $this->audit('otp_test_send', $data['phone'] . ':' . $r);

        return $r === 'success'
            ? $this->ok(['result' => 'success'])
            : $this->fail($r === 'not_found' ? 'لا مزوّدَ يدعم النصّ الحرّ' : 'تعذّر الإرسال — راجع إعدادات المزوّد');
    }

    // ══════════════════════════════════════════════════════════════

    private function audit(string $action, string $subject): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
                DB::table('audit_logs')->insert([
                    'user_id' => Auth::guard('user')->id(),
                    'action' => $action, 'subject' => mb_substr($subject, 0, 190),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('otp-center audit failed', ['e' => $e->getMessage()]);
        }
    }

    private function ok(array $meta): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => 'OK',
            'errors' => (object) [], 'meta' => $meta,
        ]);
    }

    private function fail(string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'ERROR', 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], 422);
    }
}
