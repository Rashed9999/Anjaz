<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMIAL-MERCHANT-PAY-002 — مركز فواتير التجّار.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الشرط الذي وُلد منه هذا الملفّ:**
 *
 *   «لا تبنِ شيئاً موجوداً في التطبيق ولا يظهر في لوحة الإدارة. كلُّ شيءٍ
 *    له مصدرٌ وجذرٌ قابلٌ للاستعلام والمراجعة والتعديل.»
 *
 * وفاتورةُ التاجر تُنشأ من التطبيق وتُدفع منه — **وكانت لا تُرى من
 * الإدارة إلّا في جدولٍ عامٍّ من ستّةٍ وثلاثين سطراً بلا بحثٍ ولا تصدير
 * ولا تفصيل**، اسمُه «طلبات الأموال»، يخلط طلبَ صديقٍ لصديقٍ بفاتورة
 * متجر.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا جدولَ جديداً — والمصدرُ واحد.**
 *
 * تُقرأ الفواتيرُ من `payment_requests` نفسِه الذي يكتب فيه التطبيق. فلو
 * أُنشئ جدولٌ ثانٍ «للإدارة» لصار في المنصّة حقيقتان تفترقان أوّلَ عطل،
 * ولا يُعرف أيّهما الصحيحة. **الجذرُ واحدٌ ويُقرأ من زاويتين.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما تُعدّله الإدارةُ هنا محدودٌ عمداً:**
 *
 * الإلغاءُ وحده، وللفاتورة **غير المدفوعة** فقط. أمّا المدفوعةُ فسجلٌّ
 * ماليٌّ تاريخيّ لا يُمَسّ — تصحيحُه بردٍّ (refund) يترك أثرَه، لا بمحوٍ
 * يُخفيه. (المهارة: حرمةُ السجلّات المالية التاريخيّة.)
 */
class MerchantInvoiceCenterController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function page(): View
    {
        return view('admin-views.amial.invoices.index');
    }

    // ══════════════════════════════════════════════════════════════
    // المؤشّرات — تُحسب من المصدر لا تُكتب ثابتة (القاعدة السادسة)
    // ══════════════════════════════════════════════════════════════

    public function stats(): JsonResponse
    {
        $base = $this->merchantInvoices();

        $today = (clone $base)->whereDate('created_at', today());

        $paidToday = (clone $today)->where('status', 'paid');

        $issuedToday = (int) (clone $today)->count();
        $paidTodayCount = (int) $paidToday->count();

        // **«لا فواتير اليوم» ليست «٠٪ تحصيل»** (القاعدة السابعة).
        $rate = $issuedToday > 0
            ? round(($paidTodayCount / $issuedToday) * 100, 1)
            : null;

        return response()->json(['success' => true, 'meta' => [
            'issued_today' => $issuedToday,
            'paid_today' => $paidTodayCount,
            'paid_today_amount' => (string) (clone $paidToday)->sum('amount'),
            'pending_open' => (int) (clone $base)->where('status', 'pending')->count(),
            'collection_rate' => $rate,
            'trend' => $this->trend(),
        ]]);
    }

    /** أربعةَ عشرَ يوماً — تُقرأ من الصفوف لا من عمودٍ مُجمَّع. */
    private function trend(): array
    {
        $rows = $this->merchantInvoices()
            ->where('created_at', '>=', today()->subDays(13))
            ->selectRaw('DATE(created_at) d, COUNT(*) c, SUM(status = "paid") p')
            ->groupBy('d')->pluck('c', 'd');

        $paid = $this->merchantInvoices()
            ->where('created_at', '>=', today()->subDays(13))
            ->where('status', 'paid')
            ->selectRaw('DATE(created_at) d, COUNT(*) c')
            ->groupBy('d')->pluck('c', 'd');

        $out = [];

        for ($i = 13; $i >= 0; $i--) {
            $day = today()->subDays($i)->toDateString();
            $out[] = [
                'day' => $day,
                'issued' => (int) ($rows[$day] ?? 0),
                'paid' => (int) ($paid[$day] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * **الفواتيرُ هي طلباتُ من حسابُه تاجر** — لا كلُّ طلبات الأموال.
     *
     * ولولا هذا الفصل لاختلط طلبُ صديقٍ لصديقٍ بفاتورة متجرٍ في شاشةٍ
     * واحدة، فصارت الأرقامُ كلُّها مضلِّلة.
     */
    private function merchantInvoices()
    {
        return PaymentRequest::query()
            ->whereIn('requester_user_id', function ($q) {
                $q->select('user_id')->from('merchant_profiles');
            });
    }

    // ══════════════════════════════════════════════════════════════
    // الجدول: بحث · فلاتر · ترتيب · صفحات
    // ══════════════════════════════════════════════════════════════

    public function rows(Request $request): JsonResponse
    {
        $q = $this->merchantInvoices()
            ->with(['requester:id,f_name,l_name,phone']);

        if ($s = trim((string) $request->query('search'))) {
            $q->where(function ($w) use ($s) {
                $w->where('short_code', 'like', "%{$s}%")
                  ->orWhere('amount', 'like', "%{$s}%")
                  ->orWhere('note', 'like', "%{$s}%")
                  ->orWhereHas('requester', fn ($r) => $r
                      ->where('f_name', 'like', "%{$s}%")
                      ->orWhere('l_name', 'like', "%{$s}%")
                      ->orWhere('phone', 'like', "%{$s}%"));
            });
        }

        if ($st = $request->query('status')) {
            $q->where('status', $st);
        }

        if ($from = $request->query('from')) {
            $q->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        $sort = in_array($request->query('sort'), ['amount', 'created_at', 'status'], true)
            ? $request->query('sort') : 'created_at';

        $q->orderBy($sort, $request->query('dir') === 'asc' ? 'asc' : 'desc');

        $page = $q->paginate(25)->withQueryString();

        return response()->json(['success' => true, 'meta' => [
            'rows' => collect($page->items())->map(fn ($r) => $this->row($r))->all(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]]);
    }

    private function row(PaymentRequest $r): array
    {
        $m = $r->requester;

        return [
            'id' => $r->id,
            'invoice_no' => $r->short_code,
            'merchant' => trim(($m->f_name ?? '') . ' ' . ($m->l_name ?? '')) ?: '—',
            'merchant_phone' => $m->phone ?? '—',
            'amount' => (string) $r->amount,
            'status' => $r->status,
            'note' => $r->note,
            'paid_by_user_id' => $r->paid_by_user_id,
            'transaction_id' => $r->paid_transaction_id,
            'paid_at' => $r->paid_at?->toDateTimeString(),
            'expires_at' => $r->expires_at?->toDateTimeString(),
            'created_at' => $r->created_at?->toDateTimeString(),
            'can_cancel' => $r->status === 'pending',
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // التفصيل — الجذرُ كاملاً، بما يشمل أثرَه في المال
    // ══════════════════════════════════════════════════════════════

    /**
     * **والتفصيلُ يصل إلى الحركة المالية نفسِها.**
     *
     * ففاتورةٌ «مدفوعة» بلا حركةٍ يُوصل إليها ادّعاءٌ لا يُراجَع. وهذه
     * الشاشةُ تُظهر رقمَ الحركة، **وتقول صراحةً إن لم تُوجد** بدل أن تترك
     * الفراغ يُقرأ سلامةً. (القاعدة السابعة.)
     */
    public function show(int $id): JsonResponse
    {
        $r = $this->merchantInvoices()->with('requester:id,f_name,l_name,phone')->find($id);

        if (!$r) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        $payer = $r->paid_by_user_id
            ? DB::table('users')->where('id', $r->paid_by_user_id)
                ->select('id', 'f_name', 'l_name', 'phone')->first()
            : null;

        $txn = null;
        $txnMissing = null;

        if ($r->status === 'paid') {
            if ($r->paid_transaction_id) {
                $txn = DB::table('transactions')
                    ->where('transaction_id', $r->paid_transaction_id)
                    ->select('transaction_id', 'amount', 'charge', 'transaction_type', 'created_at')
                    ->first();

                if (!$txn) {
                    $txnMissing = 'الفاتورة مدفوعة ورقمُ حركتها لا يُقابله صفٌّ في جدول الحركات';
                }
            } else {
                $txnMissing = 'الفاتورة مدفوعة بلا رقم حركة';
            }
        }

        return response()->json(['success' => true, 'meta' => [
            'invoice' => $this->row($r),
            'payer' => $payer ? [
                'id' => $payer->id,
                'name' => trim(($payer->f_name ?? '') . ' ' . ($payer->l_name ?? '')),
                'phone' => $payer->phone,
            ] : null,
            'transaction' => $txn,
            'transaction_missing' => $txnMissing,
        ]]);
    }

    // ══════════════════════════════════════════════════════════════
    // التعديل الوحيد المسموح
    // ══════════════════════════════════════════════════════════════

    /**
     * **إلغاءُ فاتورةٍ لم تُدفع — ولا شيءَ غيره.**
     *
     * والمدفوعةُ لا تُلغى ولا تُعدَّل: هي سجلٌّ ماليٌّ تاريخيّ، وتصحيحُها
     * يكون بردٍّ يترك أثرَه لا بمحوٍ يُخفيه.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $r = $this->merchantInvoices()->find($id);

        if (!$r) {
            return response()->json(['success' => false, 'message' => 'الفاتورة غير موجودة'], 404);
        }

        if ($r->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'لا تُلغى إلّا الفاتورة غير المدفوعة. حالتُها الآن: ' . $r->status,
            ], 422);
        }

        $reason = trim((string) $request->input('reason'));

        // **ولا يُلغى مالٌ بلا سبب مكتوب** — فمن راجع السجلّ بعد شهرٍ يجد
        // فعلاً بلا تفسير، ولا يعرف أكان صواباً أم خطأ.
        if ($reason === '') {
            return response()->json(['success' => false, 'message' => 'اكتب سبب الإلغاء'], 422);
        }

        $r->status = 'cancelled';
        $r->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'payment_request',
            'subject_id' => $r->id,
            'action' => 'MERCHANT_INVOICE_CANCELLED',
            'decision_code' => 'APPLIED',
            'severity' => 'warning',
            // `context` لا `data` — وإلّا بقي صفُّ التدقيق بلا سبب.
            'context' => [
                'invoice_no' => $r->short_code,
                'amount' => (string) $r->amount,
                'reason' => $reason,
            ],
        ]);

        return response()->json(['success' => true, 'message' => 'أُلغيت الفاتورة']);
    }

    // ══════════════════════════════════════════════════════════════
    // التصدير
    // ══════════════════════════════════════════════════════════════

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->merchantInvoices()
            ->with('requester:id,f_name,l_name,phone')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->limit(5000)->get();

        $name = 'merchant-invoices-' . Carbon::now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // ليفتحها إكسل بالعربيّة سليمة
            fputcsv($out, ['رقم الفاتورة', 'التاجر', 'هاتف التاجر', 'المبلغ',
                           'الحالة', 'رقم الحركة', 'تاريخ الدفع', 'أُنشئت']);

            foreach ($rows as $r) {
                $d = $this->row($r);
                fputcsv($out, [$d['invoice_no'], $d['merchant'], $d['merchant_phone'],
                               $d['amount'], $d['status'], $d['transaction_id'] ?? '',
                               $d['paid_at'] ?? '', $d['created_at']]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
