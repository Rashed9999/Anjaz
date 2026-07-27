<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\OpsHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OPS-CONSOLE-001 — شاشة التشغيل لفريق الصيانة.
 *
 * **لماذا وُجدت:** كل ما بُني من رصدٍ كان يصبّ حيث لا يصل إليه مشغّل —
 * سطرٌ في ملفّ سجلّ، أو أمرٌ يُكتب على الخادم. فبقي «لماذا لم يصل إيصال هذا
 * العميل؟» بلا جواب، والجواب موجود.
 *
 * والقاعدة التي تحكم هذه الشاشة: **كل ما يحتاجه المشغّل يظهر هنا، وكل ما
 * يفعله يُسجَّل**. فإعادة تشغيل مهمّة فاشلة فعلٌ يُغيّر حال النظام، ومن حقّ
 * من يأتي بعده أن يعرف من أعادها ومتى.
 */
class OpsConsoleController extends Controller
{
    public function __construct(
        private readonly OpsHealthService $health,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request)
    {
        return view('admin-views.amial.ops.index', [
            'snapshot' => $this->health->snapshot(),
            'canRetry' => $request->user()->hasPlatformPermission('platform.ops.retry'),
        ]);
    }

    /**
     * إعادة تشغيل المهامّ الفاشلة لصنف واحد من الأخطاء.
     *
     * لا تُعاد الفاشلة كلّها دفعةً واحدة: أكثرها يفشل للسبب نفسه، فإعادتها
     * قبل إصلاحه تُغرق الطابور وتُخفي الفشل الجديد بين القديم.
     */
    public function retry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'uuid' => 'required|string|max:64',
            'scope' => 'required|in:one,group',
        ]);

        $row = DB::table('failed_jobs')->where('uuid', $data['uuid'])->first();
        if (!$row) {
            return back()->with('error', 'المهمّة غير موجودة — ربّما أُعيدت بالفعل');
        }

        $uuids = [$data['uuid']];
        if ($data['scope'] === 'group') {
            // «الصنف» = نفس المهمّة ونفس أوّل سطر من الاستثناء.
            $head = strtok((string) $row->exception, "\n");
            $uuids = DB::table('failed_jobs')
                ->where('queue', $row->queue)
                ->where('exception', 'like', $head . '%')
                ->pluck('uuid')->all();
        }

        foreach ($uuids as $uuid) {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
        }

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'failed_job',
            'subject_id' => (string) $data['uuid'],
            'action' => 'ops_retry',
            'decision_code' => 'OPS_RETRY',
            'reason' => 'إعادة تشغيل من شاشة التشغيل',
            'severity' => 'notice',
            'context' => [
                'scope' => $data['scope'],
                'count' => count($uuids),
                'queue' => (string) $row->queue,
            ],
        ]);

        return back()->with('success', count($uuids) . ' مهمّة أُعيدت إلى الطابور');
    }

    /**
     * تشخيص توليد الـ PDF لإيصال بعينه — نفس ما يفعله amial:pdf-doctor.
     *
     * وُضع هنا لأن الجواب كان يحتاج وصولاً إلى سطر أوامر الخادم، وهو ما لا
     * يملكه فريق الصيانة ولا ينبغي أن يملكه لأجل سؤال تشخيصيّ.
     */
    public function pdfDoctor(Request $request): RedirectResponse
    {
        $data = $request->validate(['receipt_id' => 'required|integer|min:1']);

        Artisan::call('amial:pdf-doctor', ['--receipt' => $data['receipt_id']]);

        return back()->with('doctor_output', Artisan::output());
    }
}
