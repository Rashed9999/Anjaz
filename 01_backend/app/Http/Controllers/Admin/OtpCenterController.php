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
 * AMIAL-OTP-CENTER-001 — **مركز التحقّق: يُدار من شاشة، لا من نشر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤالان اللذان تُجيبهما هذه الشاشة:**
 *
 *   «لماذا لا يصل رمزُ التحقّق؟» — وكان جوابُه يحتاج فتحَ الخادم وقراءةَ
 *   السجلّات. صار في شاشةٍ واحدة: أيُّ مزوّدٍ مُفعَّل، وأيُّهم ينقصه حقل.
 *
 *   «من يستطيع التسجيل بالرمز الثابت؟» — وهو **بابٌ في نظامٍ ماليّ**.
 *   فيُرى من يفتحه ومتى، ويُقفل بضغطة.
 *
 * والصلاحيّة `platform.settings.update` على المسار: فتحُ بابِ تحقّقٍ أو
 * إقفالُه ضبطُ منصّةٍ لا خدمةُ عملاء. وموظّفُ الدعم لا يملكها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **AMIAL-OTP-TRUTH-001 — وأربعةُ أعطالٍ أُصلحت في هذه النسخة:**
 *
 * **① الشاشةُ كانت ميّتةً بالكامل.** القالبُ يدفع إلى `@push('script_2')`
 * والقالبُ الأمُّ يعرض `@stack('script')` وحدَه — **فالشيفرةُ لا تصل
 * المتصفّحَ إطلاقاً**. والصفحةُ تردّ ٢٠٠ وتبقى على «جارٍ التحميل…» أبداً.
 * الحارس: `InlineScriptDeliveryGuardTest`.
 *
 * **② و«نسبةُ النجاح» كانت اسماً يكذب.** جدولُ `phone_verifications`
 * **لا يُثبت نجاحَ تحقّق**: يُثبت أنّ محاولةً وقعت وأنّها لم تُحظَر. فرقمٌ
 * يقول «٩٨٪ نجحت» وقد يكون نصفُهم لم يُدخل الرمزَ أصلاً. فصار
 * `non_blocked_rate` باسمه الصادق.
 *
 * **③ ورسالةُ الفحص كانت تُرسَل على واتساب دائماً** مهما كانت القناةُ
 * المُختارة، ومهما كان مزوّدُها معطَّلاً. فصارت القناةُ تُختار ويُتحقّق
 * من وجود مزوّدٍ **مُفعَّلٍ يدعم النصَّ الحرّ** فيها.
 *
 * **④ وتصديرُ CSV كان يُبنى بالضمّ النصّيّ.** فاصلةٌ في الوصف تُزيح
 * الأعمدة، وقيمةٌ تبدأ بـ`=` تُنفَّذ صيغةً في جدول البيانات
 * (‏formula injection). فصار `fputcsv` مع تحييد الصيغ.
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

    public function stats(): JsonResponse
    {
        $numbers = $this->policy->demoNumbers();

        return $this->ok([
            'door_open' => $numbers !== [] && $this->policy->demoCode() !== null,
            'demo_count' => count($numbers),
            'delivery_ready' => $this->policy->deliveryReady(),
            'demo_code_set' => $this->policy->demoCode() !== null,
            'today' => $this->today(),
            'trend' => $this->trend(),
            'providers' => $this->providers(),
        ]);
    }

    /**
     * محاولاتُ اليوم.
     *
     * **و«لا محاولات» ليس «صفر نجاح»** (القاعدة السابعة): يوماً بلا
     * تسجيلٍ أصلاً تُعرض نسبتُه «—» لا «٠٪»، فصفرٌ يُقرأ عطلاً.
     *
     * **ولا يُسمّى «غيرُ المحظور» نجاحاً**: الجدولُ يُثبت المحاولةَ
     * والحظر، لا إتمامَ التحقّق. والاسمُ يُطابق ما يقيس.
     */
    private function today(): array
    {
        $total = (int) DB::table('phone_verifications')
            ->whereDate('created_at', today())
            ->count();

        $blocked = (int) DB::table('phone_verifications')
            ->whereDate('created_at', today())
            ->where('is_temp_blocked', 1)
            ->count();

        return [
            'attempts' => $total,
            'blocked' => $blocked,
            'non_blocked_rate' => $total > 0
                ? round((($total - $blocked) / $total) * 100, 1)
                : null,
        ];
    }

    /**
     * أربعةَ عشرَ يوماً — **رسمٌ يُفيد قراراً لا زينة**.
     *
     * فهبوطٌ مفاجئٌ إلى صفرٍ يعني أنّ التسجيل توقّف، وذاك ما لا يخبرك به
     * أحد: **العميلُ الذي لم يستطع التسجيل لا يشتكي، يذهب.**
     */
    private function trend(): array
    {
        $start = now()->subDays(13)->startOfDay();

        $attempts = DB::table('phone_verifications')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) d, COUNT(*) n')
            ->groupBy('d')
            ->pluck('n', 'd');

        $blocked = DB::table('phone_verifications')
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) d, SUM(CASE WHEN is_temp_blocked = 1 THEN 1 ELSE 0 END) b')
            ->groupBy('d')
            ->pluck('b', 'd');

        $out = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $out[] = [
                'date' => $date,
                'attempts' => (int) ($attempts[$date] ?? 0),
                'blocked' => (int) ($blocked[$date] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * صحّةُ كلّ مزوّد — **وهو ما يُجيب «لماذا لا يصل الرمز؟»**.
     *
     * ولا يُقال «مُفعَّل» وحدها: مزوّدٌ `status=1` ينقصه `token` يبدو
     * أخضرَ ولا يُرسل. و`testable` تفرّق بين **مُفعَّل** و**قابلٍ للفحص**:
     * قناةٌ بلا مزوّدٍ يدعم النصَّ الحرّ لا تُجرَّب برسالةٍ نصّيّة.
     */
    private function providers(): array
    {
        return array_map(function ($provider) {
            $enabled = $provider->isEnabled();
            $freeText = $provider instanceof SupportsFreeText;

            return [
                'key' => $provider->key(),
                'channel' => $provider->channel(),
                'channel_label' => $provider->channel() === 'whatsapp' ? 'واتساب' : 'رسائل SMS',
                'priority' => $provider->priority(),
                'enabled' => $enabled,
                'free_text' => $freeText,
                'testable' => $enabled && $freeText,
            ];
        }, $this->registry->all());
    }

    public function numbers(Request $request): JsonResponse
    {
        $query = DB::table('otp_demo_numbers');

        if ($search = trim((string) $request->get('search', ''))) {
            $escaped = addcslashes($search, '%_\\');
            $query->where(function ($q) use ($escaped) {
                $q->where('phone', 'like', "%{$escaped}%")
                    ->orWhere('label', 'like', "%{$escaped}%");
            });
        }

        if ($request->get('state') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->get('state') === 'disabled') {
            $query->where('is_active', false);
        }

        $sort = in_array($request->get('sort'), ['phone', 'last_used_at', 'use_count', 'created_at'], true)
            ? (string) $request->get('sort')
            : 'created_at';
        $direction = $request->get('dir') === 'asc' ? 'asc' : 'desc';
        $perPage = max(10, min((int) $request->get('per_page', 25), 100));

        $rows = $query->orderBy($sort, $direction)->paginate($perPage);

        return $this->ok([
            'rows' => $rows->items(),
            'total' => $rows->total(),
            'page' => $rows->currentPage(),
            'pages' => max(1, $rows->lastPage()),
            'from_env' => !DB::table('otp_demo_numbers')->exists(),
            'env_numbers' => (array) config('amial.otp.demo_numbers', []),
        ]);
    }

    /**
     * CSV مخصص للإدارة. نحمي النصوص من CSV/Spreadsheet formula injection.
     */
    public function export()
    {
        $rows = DB::table('otp_demo_numbers')->orderBy('phone')->get();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['الرقم', 'الوصف', 'الحالة', 'آخر استعمال', 'مرات الاستعمال']);

        foreach ($rows as $row) {
            fputcsv($stream, [
                $this->csvCell((string) $row->phone),
                $this->csvCell((string) ($row->label ?? '')),
                $row->is_active ? 'مفعّل' : 'معطّل',
                $this->csvCell((string) ($row->last_used_at ?? '')),
                (int) $row->use_count,
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="otp-demo-numbers.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:24'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $digits = (string) preg_replace('/[^0-9]/', '', $data['phone']);
        if (strlen($digits) < 7) {
            return $this->fail('رقم غير صالح');
        }

        if (DB::table('otp_demo_numbers')->where('phone', $digits)->exists()) {
            return $this->fail('الرقم مضاف سلفاً');
        }

        DB::table('otp_demo_numbers')->insert([
            'phone' => $digits,
            'label' => $data['label'] ?? null,
            'is_active' => true,
            'added_by_user_id' => Auth::guard('user')->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit('otp_demo_number.added', $digits);
        OtpPolicy::forget();

        return $this->ok(['phone' => $digits]);
    }

    /** يُعطَّل ولا يُحذف — **أثرُ من فتح باباً لا يُمحى**. */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $row = DB::table('otp_demo_numbers')->find($id);
        if (!$row) {
            return $this->fail('الرقم غير موجود');
        }

        $newState = !$row->is_active;
        DB::table('otp_demo_numbers')->where('id', $id)->update([
            'is_active' => $newState,
            'updated_at' => now(),
        ]);

        $this->audit($newState ? 'otp_demo_number.enabled' : 'otp_demo_number.disabled', (string) $row->phone);
        OtpPolicy::forget();

        return $this->ok(['is_active' => $newState]);
    }

    /**
     * **إقفالُ الباب كاملاً** — يوم الإطلاق.
     *
     * ويحتاج تأكيداً مكتوباً (`confirm=إقفال`) لا ضغطةً: فعلٌ يُوقف
     * حساباتِ العرض كلَّها، ومن يضغطه سهواً لا يعرف ما فعل حتّى يشتكي
     * أحدُهم.
     */
    public function closeDoor(Request $request): JsonResponse
    {
        if ($request->get('confirm') !== 'إقفال') {
            return $this->fail('يلزم تأكيد مكتوب');
        }

        $disabled = DB::table('otp_demo_numbers')
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        // صف معطل يمنع الرجوع إلى بذرة البيئة بعد الإقفال.
        if (!DB::table('otp_demo_numbers')->exists()) {
            DB::table('otp_demo_numbers')->insert([
                'phone' => '0',
                'label' => 'علامة إقفال — لا رقم حقيقي',
                'is_active' => false,
                'added_by_user_id' => Auth::guard('user')->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->audit('otp_demo_door.closed', (string) $disabled);
        OtpPolicy::forget();

        return $this->ok([
            'disabled' => $disabled,
            'delivery_ready' => $this->policy->deliveryReady(),
        ]);
    }

    /** إرسال رسالة فحص عبر قناة يختارها المدير من القنوات القابلة للفحص. */
    public function testSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:7', 'max:24'],
            'channel' => ['required', 'in:whatsapp,sms'],
        ]);

        $phone = (string) preg_replace('/[^0-9+]/', '', $data['phone']);
        $channel = $data['channel'];

        $hasTestableProvider = false;
        foreach ($this->registry->enabledFor($channel) as $provider) {
            if ($provider instanceof SupportsFreeText) {
                $hasTestableProvider = true;
                break;
            }
        }

        if (!$hasTestableProvider) {
            return $this->fail('لا يوجد مزود مفعّل يدعم رسالة فحص نصية في القناة المختارة');
        }

        $result = $this->registry->sendText(
            $channel,
            $phone,
            'أميال باي — رسالة فحص من مركز التحقق. ' . now()->format('H:i')
        );

        $this->audit('otp_test_send', $channel . ':' . $phone . ':' . $result);

        return $result === 'success'
            ? $this->ok(['result' => 'success', 'channel' => $channel])
            : $this->fail($result === 'not_found'
                ? 'لا مزود مفعّل يدعم النص الحر في القناة المختارة'
                : 'تعذر الإرسال — راجع إعدادات المزود وسجلات التشغيل');
    }

    /**
     * **خليّةُ CSV لا تُنفَّذ صيغةً.**
     *
     * قيمةٌ تبدأ بـ`=` أو `+` أو `-` أو `@` يقرؤها Excel وLibreOffice
     * **صيغةً تُنفَّذ عند الفتح**. ووصفُ رقمٍ يكتبه مشرفٌ يصل الجدولَ كما
     * هو. فيُسبَق بفاصلةٍ عليا تُحوّله نصّاً.
     */
    private function csvCell(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function audit(string $action, string $subject): void
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
                DB::table('audit_logs')->insert([
                    'user_id' => Auth::guard('user')->id(),
                    'action' => $action,
                    'subject' => mb_substr($subject, 0, 190),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('otp-center audit failed', ['e' => $e->getMessage()]);
        }
    }

    private function ok(array $meta): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'OK',
            'errors' => (object) [],
            'meta' => $meta,
        ]);
    }

    private function fail(string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => 'ERROR',
            'message' => $message,
            'errors' => (object) [],
            'meta' => (object) [],
        ], 422);
    }
}
