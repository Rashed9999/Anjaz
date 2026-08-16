<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OBSERVABILITY-001 — **مركزُ صحّة النظام في اللوحة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * تطلبه `amial-admin-command` بنصّها: «# SYSTEM HEALTH» و«# ERROR CENTER
 * — Connect the Error Code Engine to Admin». ولم يكن مبنيّاً.
 *
 * **وكلُّ رقمٍ هنا يُحسب من مصدره** (القاعدة السادسة): الحالةُ من فحصٍ
 * يجري الآن، والتاريخُ من `system_health_checks`، والأخطاءُ من
 * `system_errors`. لا رقمَ مخزَّنٌ يُقرأ ويُصدَّق.
 */
class SystemHealthController extends Controller
{
    /** لغةُ الخدمة الأصليّة ⇒ لغةُ العرض. */
    private const STATE = [
        'healthy' => 'up', 'warning' => 'degraded', 'unhealthy' => 'down',
    ];

    public function index(SystemHealthService $health)
    {
        // **الآنَ لا آخرَ نبضة**: النبضةُ قد تكون قبل خمس دقائق، والمشرفُ
        // يفتح الصفحة ليعرف الحالةَ الآن.
        //
        // **ومن `checkAll()` نفسِها** — الخدمةُ مبنيّةٌ منذ P0-MONITORING،
        // وبناءُ فحصٍ ثانٍ يجعل للصحّة مصدرَين يختلفان. (وقد كتبتُ واحداً
        // ثانياً فعلاً ثمّ نُزع: أمسكه `ApiContractLivenessTest` بانهيار
        // `/api/v1/amial/ping` — والدرسُ أنّ ما يُكتب فوق ملفٍّ قائمٍ يُقرأ
        // أوّلاً.)
        $result = $health->checkAll();

        $now = [];
        foreach ($result['checks'] as $component => $c) {
            $now[] = [
                'component' => $component,
                'state' => self::STATE[$c['status']] ?? 'degraded',
                'latency_ms' => isset($c['latency_ms']) ? (int) round($c['latency_ms']) : null,
                'detail' => $c['message'] ?? null,
            ];
        }

        $overall = self::STATE[$result['status']] ?? 'degraded';

        // تاريخُ أربعٍ وعشرين ساعة — لكلّ قطعةٍ عددُ ما سقط فيه.
        $since = now()->subDay();

        $history = DB::table('system_health_checks')
            ->select('component', 'state', DB::raw('COUNT(*) as n'))
            ->where('checked_at', '>=', $since)
            ->groupBy('component', 'state')
            ->get()
            ->groupBy('component');

        // **آخرُ انقطاعٍ يُسمّى بوقته** — «لا انقطاع» تُقال صراحةً ولا
        // تُترك فراغاً يُقرأ كما يشاء القارئ.
        $lastDown = DB::table('system_health_checks')
            ->where('state', 'down')
            ->orderByDesc('checked_at')
            ->first();

        // ══════════════════════════════════════════════════════════════
        //  **الاسمُ `$errors` محجوزٌ في Blade** — وهو `ViewErrorBag` الذي
        //  يقرؤه القالبُ الأمّ (`layouts/admin/app.blade.php:93`) بـ
        //  `$errors->any()`. ومتغيّرٌ بهذا الاسم من المتحكّم **يحجبه**،
        //  فتُستدعى `any()` على `Collection` لا تملكها ⇒ ٥٠٠.
        //
        //  ولا يظهر في أيّ اختبار وحدة: العطلُ في القالب الأمّ لا في هذا.
        //  **وأمسكه صاحبُ المشروع بعد الدفع بدقائق** — لأنّ البوّابةَ لم
        //  تكن تفتح هذه الصفحة.
        // ══════════════════════════════════════════════════════════════
        $defects = DB::table('system_errors')
            ->where('status_flag', '!=', 'resolved')
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        $counts = [
            'open' => DB::table('system_errors')->where('status_flag', 'open')->count(),
            'ack' => DB::table('system_errors')->where('status_flag', 'acknowledged')->count(),
            'resolved' => DB::table('system_errors')->where('status_flag', 'resolved')->count(),
            'today' => DB::table('system_errors')
                ->whereDate('last_seen_at', today())->count(),
        ];

        // **هل النبضُ يعمل أصلاً؟** جدولٌ فارغٌ يعني أنّ المهمّةَ المجدولة
        // لا تجري — وصفحةٌ خضراءُ فوق راصدٍ ميّتٍ أسوأ من صفحةٍ حمراء.
        $lastBeat = DB::table('system_health_checks')->max('checked_at');

        // **وهل يخرج الإنذارُ من الخادم أصلاً؟** (AMIAL-PROD-READINESS-001)
        //
        // كلُّ ما فوق يُكتب في جدولين، ويُقرأ من هذه الصفحة وحدَها. فإن لم
        // تكن ثَمّ قناةٌ خارجيّة، فالمشرفُ **هو** جهازُ الرصد — وقاعدةُ
        // المشروع تقول إنّه لم يعد كذلك. فتُقال الفجوةُ حيث تُقرأ، لا
        // تُترك سطراً في سجلّ.
        $hasAlertChannel = \App\Services\OpsAlertService::hasExternalChannel();

        return view('admin-views.amial.system.health', compact(
            'now', 'overall', 'history', 'lastDown', 'defects', 'counts',
            'lastBeat', 'hasAlertChannel'));
    }

    /** يُغيّر حالةَ خطأ: أُقرَّ به · حُلّ · أُعيد فتحُه. */
    public function updateError(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status_flag' => 'required|in:open,acknowledged,resolved',
            'note' => 'nullable|string|max:1000',
        ]);

        $row = DB::table('system_errors')->where('id', $id)->first();

        if (! $row) {
            return response()->json(['success' => false, 'message' => 'العطل غير موجود'], 404);
        }

        DB::table('system_errors')->where('id', $id)->update([
            'status_flag' => $data['status_flag'],
            'note' => $data['note'] ?? $row->note,
            'resolved_by_user_id' => $data['status_flag'] === 'resolved'
                ? $request->user()->id : null,
            'resolved_at' => $data['status_flag'] === 'resolved' ? now() : null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'حُدّثت الحالة']);
    }
}
