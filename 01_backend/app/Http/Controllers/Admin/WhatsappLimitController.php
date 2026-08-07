<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Services\AuditService;
use App\Services\Whatsapp\WhatsappMoneyLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * AMIAL-WA-LIMIT-001 — سقفُ المال عبر بوت واتساب، يُضبط من الإدارة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا يُضبط من شاشةٍ لا من متغيّر بيئة:**
 *
 * وقع هذا قبلاً في هذا المشروع: أرقامُ العرض للـOTP كانت تُضبط بمتغيّر
 * بيئةٍ وحده، فكان تغييرُها يعني نشراً كاملاً — **فلا تُغيَّر**. والسقفُ
 * الذي لا يُغيَّر ليس سقفاً يُدار: هو رقمٌ يُنسى.
 *
 * وهذا سقفٌ يُشدُّ ويُرخى بحسب ما يقع: بلاغُ احتيالٍ اليوم يستدعي خفضَه
 * الآن، لا بعد ستّ دقائق بناء.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والصلاحيّة `platform.money.move`:**
 *
 * لأنّ رفعَ السقف **يُحرّك مالاً بالوكالة** — لا يُحرّكه بنفسه، لكنّه
 * يسمح بحركةٍ كانت ممنوعة. ومن يملك تغييرَ حدود المال يملك المال.
 * وموظّفُ الدعم لا يملكه.
 */
class WhatsappLimitController extends Controller
{
    public function __construct(
        private readonly WhatsappMoneyLimit $limit,
        private readonly AuditService $audit,
    ) {}

    public function page(): View
    {
        return view('admin-views.amial.whatsapp.limits');
    }

    /**
     * **وتُقال حالةُ القيمة: مضبوطةٌ أم افتراضيّة.**
     *
     * فرقمٌ معروضٌ بلا بيانِ مصدره يُقرأ «مضبوط» — ثمّ يُكتشف أنّه
     * افتراضيٌّ لم يمسّه أحد. (القاعدة السابعة: الغيابُ يُقال.)
     */
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'meta' => [
            'max_amount' => $this->limit->max(),
            'is_configured' => $this->limit->isConfigured(),
            'default' => (string) config('amial.whatsapp_bot_max_amount', '50000'),
            'paths' => ['التحويل', 'دفع الفواتير', 'دفع فاتورة تاجر'],
            'blocked_today' => $this->blockedToday(),
        ]]);
    }

    /**
     * كم عمليّةً منعها السقفُ اليوم — يُقرأ من سجلّ التدقيق.
     *
     * ورقمُ صفرٍ هنا يُقرأ «لم يُمنع أحد»، وهو صحيح: السجلُّ يُكتب عند كلّ
     * منع. فليس غياباً مجهولاً.
     */
    private function blockedToday(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('audit_decisions')) {
            return 0;
        }

        return (int) DB::table('audit_decisions')
            ->where('action', 'WA_LIMIT_BLOCKED')
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * **ولا يُرفع سقفُ مالٍ بلا سبب مكتوب.**
     *
     * فمن راجع السجلّ بعد شهرٍ يجد رقماً تغيّر ولا يعرف لماذا — ولا يعرف
     * أكان قراراً أم خطأً.
     */
    public function save(Request $request): JsonResponse
    {
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            // صفرٌ مقبولٌ عمداً: هو إيقافُ المال عبر البوت.
            'max_amount' => 'required|numeric|min:0|max:100000000',
            'reason' => 'required|string|min:3|max:255',
        ], [
            'reason.required' => 'اكتب سبب التغيير',
            'max_amount.required' => 'اكتب الحدّ الأعلى',
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'message' => $v->errors()->first(),
                'errors' => $v->errors(),
            ], 422);
        }

        $old = $this->limit->max();
        $new = (string) $request->input('max_amount');

        // **ولا `updateOrCreate` هنا:** `BusinessSetting` ليس فيه `key`
        // ضمن `fillable`، فيرمي `MassAssignmentException`. كشفه أوّلُ
        // اختبار — ولولاه لانفجرت الشاشةُ عند أوّل حفظٍ في الإنتاج.
        DB::table('business_settings')->updateOrInsert(
            ['key' => WhatsappMoneyLimit::KEY],
            ['value' => $new, 'updated_at' => now(), 'created_at' => now()],
        );

        // **وتُنسى الذاكرةُ فوراً** — وإلّا بقي السقفُ القديم عاملاً دقيقةً
        // كاملة، وهي كافيةٌ لمن يُلاحَق الآن. (زرٌّ يقول «حُفظ» ولا يفعل.)
        WhatsappMoneyLimit::forget();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'setting',
            'subject_id' => 0,
            'action' => 'WA_LIMIT_CHANGED',
            'decision_code' => 'APPLIED',
            'severity' => bccomp($new, $old, 4) === 1 ? 'warning' : 'info',
            // `context` لا `data` — `AuditService` يقرأ الأوّل وحده.
            'context' => [
                'from' => $old,
                'to' => $new,
                'reason' => trim((string) $request->input('reason')),
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'حُفظ الحدّ الأعلى',
            'meta' => ['max_amount' => $this->limit->max(), 'is_configured' => true],
        ]);
    }
}
